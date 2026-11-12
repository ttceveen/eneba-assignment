import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'

// Use Node's native runner without adding a test framework or changing Vite config.
const source = (await readFile(new URL('../src/api/games.ts', import.meta.url), 'utf8'))
  .replace('import.meta.env.VITE_API_BASE_URL', JSON.stringify('https://api.test/api'))
const { getGames, getGame } = await import(`data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(source)).toString('base64')}`)
const game = { id: 1, name: 'Fixture game', location: ['GLOBAL'], img: null }
const page = (data = [game]) => ({
  data, links: { first: '/games?page=1', last: '/games?page=1', prev: null, next: null },
  meta: { current_page: 1, last_page: 1, per_page: 12, total: data.length },
})
const signal = () => new AbortController().signal
function reply(t, payload, status = 200) {
  return t.mock.method(globalThis, 'fetch', async () => Response.json(payload, { status }))
}

test('valid games preserve nullable images, regions and API order', async (t) => {
  const games = [game, { ...game, id: 2, location: ['LT', 'PL'], img: '/cover.jpg' }]
  const fetch = reply(t, page(games))
  assert.deepEqual(await getGames(1, 'alphabetical', signal()), page(games))
  assert.equal(fetch.mock.calls[0].arguments[0], 'https://api.test/api/games?sort=alphabetical&page=1')
})
test('valid detail and nullable image are returned unchanged', async (t) => {
  reply(t, { data: game })
  assert.deepEqual(await getGame('1', signal()), game)
})
test('empty catalog remains valid', async (t) => {
  reply(t, page([]))
  assert.deepEqual((await getGames(1, 'popular', signal())).data, [])
})

const invalidGames = [
  ...['id', 'name', 'location', 'img'].map((field) => {
    const value = { ...game }; delete value[field]
    return [`missing ${field}`, value]
  }),
  ['null game', null], ['array game', []],
  ['null location', { ...game, location: null }],
  ['string location', { ...game, location: 'GLOBAL' }],
  ['nonstring country', { ...game, location: ['LT', 42] }],
  ['null name', { ...game, name: null }],
  ['invalid image', { ...game, img: false }],
  ...['1', null, 0, -1, 1.5, Number.MAX_SAFE_INTEGER + 1].map((id) => [`invalid id ${id}`, { ...game, id }]),
]
for (const [label, value] of invalidGames) {
  test(`list rejects ${label}, including an invalid item after a valid one`, async (t) => {
    reply(t, page([game, value]))
    await assert.rejects(getGames(1, 'popular', signal()), /Invalid games response/)
  })
  test(`detail rejects ${label}`, async (t) => {
    reply(t, { data: value })
    await assert.rejects(getGame('1', signal()), /Invalid game response/)
  })
}
for (const payload of [null, [], {}, { data: null }, { ...page(), meta: null },
  { ...page(), links: null }, { ...page(), meta: { ...page().meta, current_page: 0 } }]) {
  test(`list rejects malformed envelope ${JSON.stringify(payload)}`, async (t) => {
    reply(t, payload)
    await assert.rejects(getGames(1, 'popular', signal()), /Invalid games response/)
  })
}
test('detail rejects a missing envelope', async (t) => {
  reply(t, null)
  await assert.rejects(getGame('1', signal()), /Invalid game response/)
})
test('404 detail still means not found', async (t) => {
  reply(t, { message: 'Not found' }, 404)
  assert.equal(await getGame('1', signal()), null)
})
test('HTTP failures remain controlled errors', async (t) => {
  reply(t, { message: 'Unavailable' }, 503)
  await assert.rejects(getGames(1, 'popular', signal()), /HTTP 503/)
  await assert.rejects(getGame('1', signal()), /HTTP 503/)
})
test('invalid JSON rejects before rendering', async (t) => {
  t.mock.method(globalThis, 'fetch', async () => new Response('{broken'))
  await assert.rejects(getGames(1, 'popular', signal()), SyntaxError)
  await assert.rejects(getGame('1', signal()), SyntaxError)
})
