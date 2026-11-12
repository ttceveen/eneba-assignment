import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'

const moduleUrl = (source) => `data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(source)).toString('base64')}`
const api = moduleUrl((await readFile(new URL('../src/api/games.ts', import.meta.url), 'utf8'))
  .replace('import.meta.env.VITE_API_BASE_URL', JSON.stringify('https://api.test/api')))
const resolverSource = (await readFile(new URL('../src/cart/resolveOffers.ts', import.meta.url), 'utf8'))
  .replace("'../api/games'", JSON.stringify(api))
const { resolveCartOffers } = await import(moduleUrl(resolverSource))
const offer = (id, sellerId = id) => ({ id, seller: { id: sellerId, name: 'Seller '+sellerId }, price: '19.99', quantity: 3 })
const game = (id, offers) => ({ id, name: 'Game '+id, location: ['GLOBAL'], img: null, min_price: offers.length ? '19.99' : null, offers_count: offers.length, offers })
const page = (games, current = 1, last = 1) => ({ data: games.map(({ offers: _offers, ...summary }) => summary), meta: { current_page: current, last_page: last, total: games.length, per_page: 12 }, links: { first: '', last: '', prev: null, next: null } })
const signal = () => new AbortController().signal
function transport(t, handler) {
  const requests = []
  t.mock.method(globalThis, 'fetch', async (path, options) => {
    requests.push({ path, signal: options.signal })
    return handler(new URL(path), options)
  })
  return requests
}

test('refreshes one known game for separate seller offers without a catalog scan', async (t) => {
  const actual = game(10, [offer(1, 100), offer(2, 200)])
  const requests = transport(t, () => Response.json({ data: actual }))
  const s = signal()
  const result = await resolveCartOffers([1, 2], new Map([[1, 10], [2, 10]]), s)
  assert.equal(requests.length, 1)
  assert.equal(requests[0].path, 'https://api.test/api/games/10')
  assert.equal(requests[0].signal, s)
  assert.equal(result.get(1).offer.seller.id, 100)
  assert.equal(result.get(2).offer.seller.id, 200)
})
test('cold restore uses paginated catalog and details, skips empty games and stops when found', async (t) => {
  const first = game(10, []), second = game(20, [offer(2)]), third = game(30, [offer(3)])
  const requests = transport(t, (url) => {
    if (url.pathname === '/api/games') return Response.json(url.searchParams.get('page') === '1' ? page([first, second], 1, 3) : page([third], 2, 3))
    return Response.json({ data: url.pathname.endsWith('/20') ? second : third })
  })
  const result = await resolveCartOffers([2, 3], new Map(), signal())
  assert.deepEqual([...result.keys()], [2, 3])
  assert.equal(requests.length, 4)
  assert.ok(requests.every((r) => !r.path.endsWith('/10') && !r.path.includes('page=3')))
})
test('a stale game hint does not misclassify an offer that moved to another game', async (t) => {
  const actual = game(20, [offer(1)])
  transport(t, (url) => url.pathname.endsWith('/10') ? Response.json({}, { status: 404 })
    : Response.json(url.pathname === '/api/games' ? page([actual]) : { data: actual }))
  const result = await resolveCartOffers([1], new Map([[1, 10]]), signal())
  assert.equal(result.get(1).game.id, 20)
})
test('unavailable or deleted offers remain absent after a complete successful lookup', async (t) => {
  const requests = transport(t, () => Response.json(page([game(10, [])])))
  const result = await resolveCartOffers([999], new Map(), signal())
  assert.equal(result.size, 0)
  assert.equal(requests.length, 1)
})
test('network failure is an error rather than a claim that a saved offer was deleted', async (t) => {
  transport(t, () => Response.json({}, { status: 503 }))
  await assert.rejects(resolveCartOffers([1], new Map(), signal()), /HTTP 503/)
})
test('old API details without offers and malformed stock fail safely', async (t) => {
  const old = game(10, []); delete old.offers
  const fetch = t.mock.method(globalThis, 'fetch', async () => Response.json({ data: old }))
  await assert.rejects(resolveCartOffers([1], new Map([[1, 10]]), signal()), /do not provide seller offers/)
  fetch.mock.mockImplementation(async () => Response.json({ data: game(10, [{ ...offer(1), quantity: 0 }]) }))
  await assert.rejects(resolveCartOffers([1], new Map([[1, 10]]), signal()), /Invalid game response/)
})
test('empty and pre-aborted lookups make no HTTP requests', async (t) => {
  const requests = transport(t, () => { throw Error('Unexpected request') })
  assert.equal((await resolveCartOffers([], new Map(), signal())).size, 0)
  const controller = new AbortController(); controller.abort()
  await assert.rejects(resolveCartOffers([1], new Map(), controller.signal), { name: 'AbortError' })
  assert.equal(requests.length, 0)
})
