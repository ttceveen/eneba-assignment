import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'
const moduleUrl = s => `data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(s)).toString('base64')}`
async function client(base = 'https://api.test/api', production = false) {
  const games = moduleUrl((await readFile(new URL('../src/api/games.ts', import.meta.url), 'utf8')).replace('import.meta.env.VITE_API_BASE_URL', JSON.stringify(base)))
  return import(moduleUrl((await readFile(new URL('../src/api/auth.ts', import.meta.url), 'utf8')).replace("'./games'", JSON.stringify(games)).replace('import.meta.env.PROD', String(production))))
}
const { authenticate, getMe, logout, AuthError } = await client()
const user = { id: 1, first_name: 'John', last_name: 'Doe', email: 'john@example.com', status: 'active' }
const signal = () => new AbortController().signal
function transport(t, payload = { data: user, token: 'fixture-token' }, status = 200) {
  const calls = []
  t.mock.method(globalThis, 'fetch', async (url, options) => { calls.push({ url, options }); return Response.json(payload, { status }) })
  return calls
}
test('register and login each POST once without CSRF/cookies and return token', async t => {
  const calls = transport(t)
  for (const mode of ['login', 'register']) {
    const data = { email: user.email, password: 'SecurePassword123', ...(mode === 'register' ? { first_name: 'John', last_name: 'Doe' } : {}) }
    assert.deepEqual(await authenticate(mode, data, signal()), { data: user, token: 'fixture-token' })
    const r = calls.at(-1); assert.equal(r.url, 'https://api.test/api/' + mode)
    assert.equal(r.options.method, 'POST'); assert.deepEqual(JSON.parse(r.options.body), data)
    assert.equal(r.options.headers.get('X-XSRF-TOKEN'), null); assert.equal(r.options.headers.get('Authorization'), null)
    assert.equal(r.options.credentials, 'omit')
  }
  assert.equal(calls.length, 2)
})
test('me and logout use bearer header only, never URL, cookies or CSRF', async t => {
  const calls = transport(t); const s = signal()
  assert.deepEqual(await getMe('fixture-token', s), user); await logout('fixture-token', s)
  assert.deepEqual(calls.map(c => new URL(c.url).pathname), ['/api/me', '/api/logout'])
  assert.deepEqual(calls.map(c => c.options.method), ['GET', 'POST'])
  for (const c of calls) {
    assert.equal(c.options.credentials, 'omit'); assert.equal(c.options.signal, s)
    assert.equal(c.options.headers.get('Authorization'), 'Bearer fixture-token')
    assert.equal(c.options.headers.get('X-XSRF-TOKEN'), null); assert.ok(!c.url.includes('fixture-token'))
  }
})
test('validation, blocked, expired and rate limit errors retain status', async t => {
  for (const status of [422, 403, 401, 429]) {
    transport(t, { message: 'Rejected', errors: { email: ['Incorrect'] } }, status)
    await assert.rejects(getMe('fixture-token', signal()), e => e instanceof AuthError && e.status === status && e.errors.email[0] === 'Incorrect')
  }
})
test('malformed successful users or missing tokens are rejected', async t => {
  for (const data of [null, {}, { ...user, status: 'blocked' }, { ...user, id: 0 }]) {
    transport(t, { data, token: 'fixture-token' })
    await assert.rejects(getMe('fixture-token', signal()), /Invalid/)
    await assert.rejects(authenticate('login', { email: user.email, password: 'password' }, signal()), /Invalid/)
  }
  transport(t, { data: user })
  await assert.rejects(authenticate('login', { email: user.email, password: 'password' }, signal()), /Invalid/)
})
test('production rejects HTTP before sending any authentication request', async t => {
  const calls = transport(t)
  Object.defineProperty(globalThis, 'window', { configurable: true, value: { location: { origin: 'https://shop.test' } } })
  t.after(() => delete globalThis.window)
  const insecure = await client('http://api.test/api', true)
  await assert.rejects(insecure.getMe('fixture-token', signal()), /HTTPS/)
  await assert.rejects(insecure.authenticate('login', { email: user.email, password: 'password' }, signal()), /HTTPS/)
  assert.equal(calls.length, 0)
  const secure = await client('https://api.test/api', true)
  await secure.getMe('fixture-token', signal()); assert.equal(calls.length, 1)
})
