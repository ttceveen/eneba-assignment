import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'
const moduleUrl = s => `data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(s)).toString('base64')}`
const cartSource = await readFile(new URL('../src/cart/cart.ts', import.meta.url), 'utf8')
const journal = await import(moduleUrl(await readFile(new URL('../src/checkout/cartPurchases.ts', import.meta.url), 'utf8')))
const session = await import(moduleUrl((await readFile(new URL('../src/checkout/session.ts', import.meta.url), 'utf8')).replace("'../cart/cart'", JSON.stringify(moduleUrl(cartSource)))))
function storage(t) {
  const values = new Map()
  const local = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }
  t.stub = local
  Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: local })
  Object.defineProperty(globalThis, 'sessionStorage', { configurable: true, value: local })
  t.after(() => { delete globalThis.localStorage; delete globalThis.sessionStorage })
}
test('checkout preserves request key and original cart across reload/retry', t => {
  storage(t)
  const first = session.checkoutAttempt(1, [{ offerId: 1, quantity: 2 }])
  assert.deepEqual(session.checkoutAttempt(1, [{ offerId: 2, quantity: 1 }]), first)
  session.saveSession(session.checkoutKey(1), { ...first, tracked: true })
  assert.equal(session.checkoutAttempt(1, [{ offerId: 2, quantity: 1 }]).tracked, true)
  assert.notEqual(session.checkoutAttempt(2, [{ offerId: 2, quantity: 1 }]).key, first.key)
})
test('paid units are subtracted once while later additions and other offers survive', t => {
  storage(t)
  journal.beginPurchase('request', 1, [{ offerId: 1, quantity: 2 }]); journal.bindPurchase('request', 1, 3)
  const current = [{ offerId: 1, quantity: 4 }, { offerId: 2, quantity: 1 }]
  const next = journal.preparePurchase(1, 3, current, [{ offerId: 1, quantity: 2 }])
  assert.deepEqual(next, [{ offerId: 1, quantity: 2 }, { offerId: 2, quantity: 1 }])
  journal.finishPurchase(1, 3)
  assert.equal(journal.preparePurchase(1, 3, next, [{ offerId: 1, quantity: 2 }]), null)
})
test('removing original units and readding the offer never consumes the later units', t => {
  storage(t)
  const original = [{ offerId: 1, quantity: 2 }]
  journal.beginPurchase('request', 1, original); journal.bindPurchase('request', 1, 3)
  journal.recordCartChange(original, [])
  journal.recordCartChange([], [{ offerId: 1, quantity: 1 }])
  assert.deepEqual(journal.preparePurchase(1, 3, [{ offerId: 1, quantity: 1 }], original), [{ offerId: 1, quantity: 1 }])
})
test('decreases remove original allocation first and do not overclear the cart', t => {
  storage(t)
  journal.beginPurchase('request', 1, [{ offerId: 1, quantity: 2 }]); journal.bindPurchase('request', 1, 3)
  journal.recordCartChange([{ offerId: 1, quantity: 4 }], [{ offerId: 1, quantity: 3 }])
  assert.deepEqual(journal.preparePurchase(1, 3, [{ offerId: 1, quantity: 3 }], [{ offerId: 1, quantity: 2 }]), [{ offerId: 1, quantity: 2 }])
})
test('unknown order or other user does not change the cart', t => {
  storage(t)
  journal.beginPurchase('request', 1, [{ offerId: 1, quantity: 2 }]); journal.bindPurchase('request', 1, 3)
  assert.equal(journal.preparePurchase(2, 3, [{ offerId: 1, quantity: 2 }], [{ offerId: 1, quantity: 2 }]), null)
  assert.equal(journal.preparePurchase(1, 4, [{ offerId: 1, quantity: 2 }], [{ offerId: 1, quantity: 2 }]), null)
})
test('interrupted receipt does not subtract twice after cart write', t => {
  storage(t)
  const original = [{ offerId: 1, quantity: 2 }]
  journal.beginPurchase('request', 1, original); journal.bindPurchase('request', 1, 3)
  const next = journal.preparePurchase(1, 3, original, original)
  assert.deepEqual(next, [])
  assert.equal(journal.preparePurchase(1, 3, next, original), null)
})
const games = moduleUrl((await readFile(new URL('../src/api/games.ts', import.meta.url), 'utf8')).replace('import.meta.env.VITE_API_BASE_URL', JSON.stringify('https://api.test/api')))
const api = await import(moduleUrl((await readFile(new URL('../src/api/checkout.ts', import.meta.url), 'utf8')).replace("'./games'", JSON.stringify(games)).replace('import.meta.env.PROD', 'false')))
const order = { id: 1, status: 'pending_payment', items: [{ id: 1, offer_id: 2, quantity: 1, unit_price: '3.00', game: { id: 1, name: 'Fixture', img: null }, seller: { id: 1, name: 'Seller' } }], total_amount: '3.00', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(), payments: [] }
test('checkout sends only IDs/quantities, Bearer and stable Idempotency-Key, without cookies', async t => {
  const calls = []
  t.mock.method(globalThis, 'fetch', async (url, options) => { calls.push({ url, options }); return Response.json({ data: order }) })
  await api.createOrder('fixture-token', [{ offerId: 2, quantity: 1 }], 'same-key', new AbortController().signal)
  const { options } = calls[0]
  assert.deepEqual(JSON.parse(options.body), { items: [{ offer_id: 2, quantity: 1 }] })
  assert.equal(options.headers.get('Idempotency-Key'), 'same-key'); assert.equal(options.headers.get('Authorization'), 'Bearer fixture-token')
  assert.equal(options.credentials, 'omit'); assert.equal(options.cache, 'no-store')
})
test('technical backend error messages never reach the checkout UI', async t => {
  t.mock.method(globalThis, 'fetch', async () => Response.json({ message: 'SQLSTATE sensitive database details' }, { status: 409 }))
  await assert.rejects(api.getOrder('fixture', 1, new AbortController().signal), error => !api.checkoutMessage(error).includes('SQLSTATE'))
})
test('malformed successful order response is rejected before rendering', async t => {
  t.mock.method(globalThis, 'fetch', async () => Response.json({ data: [] }))
  await assert.rejects(api.getOrder('fixture', 1, new AbortController().signal))
})

const paypalPayment = { id: 7, order_id: 1, provider: 'paypal', external_id: 'ORDER123', redirect_url: 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', idempotency_key: 'stable', status: 'pending', amount: '3.00', currency: 'EUR', error_code: null, error_message: null }
test('PayPal creation sends only provider and uses the stable existing payment endpoint', async t => {
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.equal(new URL(url).pathname, '/api/orders/1/payments')
    assert.deepEqual(JSON.parse(options.body), { provider: 'paypal' })
    assert.equal(options.headers.get('Idempotency-Key'), 'stable')
    return Response.json({ data: paypalPayment })
  })
  assert.deepEqual(await api.createPayment('fixture', 1, 'stable', new AbortController().signal, 'paypal'), paypalPayment)
})
test('PayPal confirmation sends only the PayPal Order ID with existing Bearer auth', async t => {
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.equal(new URL(url).pathname, '/api/payments/paypal/confirm')
    assert.equal(options.method, 'POST')
    assert.equal(options.headers.get('Authorization'), 'Bearer fixture')
    assert.deepEqual(JSON.parse(options.body), { paypal_order_id: 'ORDER123' })
    assert.equal(options.credentials, 'omit')
    return Response.json({ data: { ...paypalPayment, status: 'succeeded' } })
  })
  assert.equal((await api.confirmPayPalPayment('ORDER123', 'fixture', new AbortController().signal)).status, 'succeeded')
})
test('PayPal invalid Order IDs fail before HTTP', async t => {
  let calls = 0
  t.mock.method(globalThis, 'fetch', async () => { calls++; return Response.json({}) })
  for (const id of ['', 'lowercase', 'ORDER/123', 'ORDER123\n', 'A'.repeat(37)]) await assert.rejects(api.confirmPayPalPayment(id, 'fixture', new AbortController().signal))
  assert.equal(calls, 0)
})
test('PayPal confirmation rejects a different provider or external order identity', async t => {
  for (const patch of [{ provider: 'fake_card' }, { external_id: 'OTHER123' }]) {
    t.mock.method(globalThis, 'fetch', async () => Response.json({ data: { ...paypalPayment, ...patch } }))
    await assert.rejects(api.confirmPayPalPayment('ORDER123', 'fixture', new AbortController().signal))
    t.mock.restoreAll()
  }
})
test('PayPal redirects permit only the Sandbox checkout host', () => {
  assert.equal(api.payPalApprovalUrl(paypalPayment.redirect_url), paypalPayment.redirect_url)
  for (const url of [null, '', 'javascript:alert(1)', 'http://www.sandbox.paypal.com/checkoutnow', 'https://www.paypal.com/checkoutnow', 'https://www.sandbox.paypal.com.evil.test/', 'https://user@www.sandbox.paypal.com/', 'https://www.sandbox.paypal.com:444/']) assert.throws(() => api.payPalApprovalUrl(url))
})
test('Fake Card remains the default provider for existing callers', async t => {
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.deepEqual(JSON.parse(options.body), { provider: 'fake_card' })
    return Response.json({ data: { ...paypalPayment, provider: 'fake_card', external_id: 'fake_card_7', redirect_url: null } })
  })
  assert.equal((await api.createPayment('fixture', 1, 'stable', new AbortController().signal)).provider, 'fake_card')
})

test('PayPal confirmation has a bounded timeout and keeps the caller cancellation signal', async t => {
  const expired = AbortSignal.abort(new DOMException('Timed out', 'TimeoutError'))
  t.mock.method(AbortSignal, 'timeout', ms => { assert.equal(ms, 45000); return expired })
  t.mock.method(globalThis, 'fetch', async (url, options) => { options.signal.throwIfAborted(); return Response.json({ data: paypalPayment }) })
  await assert.rejects(api.confirmPayPalPayment('ORDER123', 'fixture', new AbortController().signal), { name: 'TimeoutError' })
  t.mock.restoreAll()
  const controller = new AbortController(); controller.abort()
  t.mock.method(globalThis, 'fetch', async (url, options) => { options.signal.throwIfAborted(); return Response.json({ data: paypalPayment }) })
  await assert.rejects(api.confirmPayPalPayment('ORDER123', 'fixture', controller.signal), { name: 'AbortError' })
})
