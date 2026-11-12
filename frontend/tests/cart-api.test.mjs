import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'
const moduleUrl = (s) => `data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(s)).toString('base64')}`
const api = moduleUrl((await readFile(new URL('../src/api/games.ts', import.meta.url), 'utf8')).replace('import.meta.env.VITE_API_BASE_URL', JSON.stringify('https://api.test/api')))
const { resolveCartOffers } = await import(moduleUrl((await readFile(new URL('../src/cart/resolveOffers.ts', import.meta.url), 'utf8')).replace("'../api/games'", JSON.stringify(api))))
const offer = (id, extra = {}) => ({ id, game: { id: 10, name: 'Game', img: null }, seller: { id, name: 'Seller '+id }, price: '19.99', currency: 'EUR', quantity: 3, available: true, ...extra })
const signal = () => new AbortController().signal
function transport(t, data, status = 200) {
  const requests = []
  t.mock.method(globalThis, 'fetch', async (path, options) => { requests.push({ path, signal: options.signal }); return Response.json(data, { status }) })
  return requests
}
test('cold restore makes one batch HTTP request, deduplicates IDs, preserves seller/price/stock', async (t) => {
  const requests = transport(t, { data: [offer(1), offer(2)] })
  const s = signal()
  const result = await resolveCartOffers([1, 2, 1], s)
  assert.equal(requests.length, 1)
  assert.equal(decodeURIComponent(requests[0].path), 'https://api.test/api/offers?ids=1,2')
  assert.equal(requests[0].signal, s)
  assert.equal(result.get(2).offer.seller.id, 2)
  assert.equal(result.get(1).offer.price, '19.99')
  assert.equal(result.get(1).offer.quantity, 3)
})
test('missing and unavailable offers remain absent without catalog fallback', async (t) => {
  const requests = transport(t, { data: [offer(1, { available: false, quantity: 0 })] })
  assert.equal((await resolveCartOffers([1, 99], signal())).size, 0)
  assert.equal(requests.length, 1)
})
test('API errors propagate rather than silently discarding saved items', async (t) => {
  transport(t, {}, 503)
  await assert.rejects(resolveCartOffers([1], signal()), /503/)
})
test('invalid price, currency, quantity, unsolicited and duplicate IDs are rejected', async (t) => {
  for (const data of [[offer(1, { price: 9 })], [offer(1, { currency: 'USD' })], [offer(1, { quantity: -1 })], [offer(2)], [offer(1), offer(1)]]) {
    t.mock.method(globalThis, 'fetch', async () => Response.json({ data }))
    await assert.rejects(resolveCartOffers([1], signal()), /response/)
  }
})
test('empty cart and invalid oversized input never send a request', async (t) => {
  const requests = transport(t, { data: [] })
  assert.equal((await resolveCartOffers([], signal())).size, 0)
  await assert.rejects(resolveCartOffers(Array.from({length: 101}, (_, i) => i + 1), signal()))
  assert.equal(requests.length, 0)
})
test('aborted lookup never sends a request', async (t) => {
  const requests = transport(t, { data: [] })
  const c = new AbortController(); c.abort()
  await assert.rejects(resolveCartOffers([1], c.signal))
  assert.equal(requests.length, 0)
})
