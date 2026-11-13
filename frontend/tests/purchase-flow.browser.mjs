import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const test = (name, run) => browserTest(name, { timeout: 25000 }, run)
const user = { id: 1, first_name: 'Demo', last_name: 'User', email: 'fixture@example.test', status: 'active' }
const game = id => ({ id, name: `Fixture Game ${id}`, img: null, location: ['GLOBAL'], min_price: '9.99', offers_count: 1, offers: [{ id, price: '9.99', quantity: 5, seller: { id, name: `Seller ${id}` } }] })
const offer = id => ({ ...game(id).offers[0], game: { id, name: `Fixture Game ${id}`, img: null }, currency: 'EUR', available: true })
const fulfill = (route, status, data) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })
async function setup(t, saved = [{ offerId: 1, quantity: 1 }]) {
  const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] })
  const errors = [], checkoutCalls = [], paymentCalls = []
  const state = { order: null, payments: [], checkoutLoss: false, paymentLoss: false, simulationLoss: false, keysLoss: false, keyRequests: 0, expireSoon: false, checkoutConflict: false }
  await context.addInitScript(({ saved }) => {
    if (!/^https?:$/.test(location.protocol)) return
    if (!sessionStorage.getItem('purchase-fixture-init')) {
      sessionStorage.setItem('purchase-fixture-init', '1'); sessionStorage.setItem('eneba:auth-token', 'fixture-token')
      localStorage.setItem('eneba:cart', JSON.stringify(saved))
    }
  }, { saved })
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname
    if (path === '/api/me') return fulfill(route, 200, { data: user })
    if (path === '/api/offers') return fulfill(route, 200, { data: url.searchParams.get('ids').split(',').map(Number).map(offer) })
    if (/^\/api\/games\/\d+$/.test(path)) return fulfill(route, 200, { data: game(Number(path.split('/').at(-1))) })
    if (path === '/api/checkout') {
      checkoutCalls.push({ key: request.headers()['idempotency-key'], body: request.postDataJSON() })
      if (state.checkoutConflict) return fulfill(route, 409, { message: 'SQLSTATE should not be displayed' })
      if (!state.order) state.order = { id: 1, status: 'pending_payment', items: request.postDataJSON().items.map((i, n) => ({ id: n + 1, ...i, unit_price: '9.99', game: offer(i.offer_id).game, seller: offer(i.offer_id).seller })), total_amount: '9.99', currency: 'EUR', reserved_until: new Date(Date.now() + (state.expireSoon ? 1500 : 900000)).toISOString(), payments: state.payments }
      if (state.checkoutLoss) { state.checkoutLoss = false; return fulfill(route, 503, {}) }
      return fulfill(route, 201, { data: state.order })
    }
    if (path === '/api/orders/1') {
      if (state.order?.status === 'pending_payment' && Date.parse(state.order.reserved_until) <= Date.now()) state.order.status = 'expired'
      return fulfill(route, 200, { data: { ...state.order, payments: [...state.payments].reverse() } })
    }
    if (path === '/api/orders/1/payments') {
      paymentCalls.push(request.headers()['idempotency-key'])
      let payment = state.payments.find(p => p.idempotency_key === request.headers()['idempotency-key'])
      if (!payment) { payment = { id: state.payments.length + 1, order_id: 1, provider: 'fake_card', idempotency_key: request.headers()['idempotency-key'], status: 'pending', amount: '9.99', currency: 'EUR', error_code: null, error_message: null }; state.payments.push(payment) }
      if (state.paymentLoss) { state.paymentLoss = false; return fulfill(route, 503, {}) }
      return fulfill(route, 201, { data: payment })
    }
    if (/^\/api\/payments\/\d+\/simulate$/.test(path)) {
      const payment = state.payments.find(p => p.id === Number(path.split('/')[3]))
      payment.status = request.postDataJSON().result === 'success' ? 'succeeded' : 'failed'
      if (payment.status === 'succeeded') state.order.status = 'paid'
      if (state.simulationLoss) { state.simulationLoss = false; return fulfill(route, 503, {}) }
      return fulfill(route, 200, { data: payment })
    }
    if (path === '/api/orders/1/keys') {
      state.keyRequests++
      if (state.order?.status !== 'paid') return fulfill(route, 409, {})
      if (state.keysLoss) { state.keysLoss = false; return fulfill(route, 503, {}) }
      return fulfill(route, 200, { data: { order_id: 1, items: state.order.items.map(i => ({ order_item_id: i.id, offer_id: i.offer_id, keys: [{ id: i.id, key_value: 'SYNTHETIC-DEMO-KEY' }] })) } })
    }
    if (path === '/api/orders/1/cancel') { state.order.status = 'cancelled'; return fulfill(route, 200, { data: state.order }) }
    return fulfill(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: { first: '', last: '', prev: null, next: null } })
  })
  context.on('page', p => p.on('pageerror', e => errors.push(e.message)))
  const page = await context.newPage(); page.setDefaultTimeout(7000)
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  await page.goto(base + '/cart')
  return { page, context, state, checkoutCalls, paymentCalls }
}
async function checkout(page) {
  await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).click()
  await page.waitForURL('**/orders/1')
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).waitFor()
}
const cart = page => page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart')))
test('remove, rapid quantity changes, totals, header and reload use existing cart', async t => {
  const { page } = await setup(t, [{ offerId: 1, quantity: 1 }, { offerId: 2, quantity: 1 }])
  await page.getByLabel('Cart total', { exact: true }).filter({ hasText: '€' }).waitFor()
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).evaluate(button => { for (let i = 0; i < 20; i++) button.click() })
  assert.equal((await cart(page))[0].quantity, 5)
  await page.locator('[data-offer-id="2"]').getByRole('button', { name: 'Remove item from cart', exact: true }).click()
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 5 }])
  await page.getByRole('link', { name: 'Shopping cart, 5 items', exact: true }).waitFor()
  await page.reload(); await page.getByLabel('Quantity for offer 1', { exact: true }).filter({ hasText: '5' }).waitFor()
  await page.locator('[data-offer-id="1"]').getByRole('button', { name: 'Remove item from cart', exact: true }).click()
  await page.getByRole('heading', { name: 'Your cart is empty' }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).count(), 0)
})
test('checkout network retry retains one key and immutable body', async t => {
  const { page, state, checkoutCalls } = await setup(t)
  state.checkoutLoss = true
  await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).click()
  await page.getByRole('button', { name: 'Retry checkout', exact: true }).click()
  await page.waitForURL('**/orders/1')
  assert.ok(checkoutCalls.length >= 2)
  assert.equal(new Set(checkoutCalls.map(c => c.key)).size, 1)
  assert.deepEqual(checkoutCalls[0].body, checkoutCalls.at(-1).body)
})
test('reload keeps order and original deadline without creating another order', async t => {
  const { page, checkoutCalls } = await setup(t)
  await checkout(page)
  const before = checkoutCalls.length
  await page.reload(); await page.getByRole('heading', { name: 'Order #1', exact: true }).waitFor()
  assert.equal(checkoutCalls.length, before)
  await page.getByRole('timer').filter({ hasText: /1[45]:/ }).waitFor()
})
test('failed payment retains cart; retry creates new attempt then clears only bought units', async t => {
  const { page, paymentCalls } = await setup(t)
  await checkout(page)
  await page.getByLabel('Failed payment', { exact: true }).check()
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.getByRole('button', { name: 'Try payment again', exact: true }).waitFor()
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 1 }])
  await page.getByLabel('Successful payment', { exact: true }).check()
  await page.getByRole('button', { name: 'Try payment again', exact: true }).click()
  await page.waitForURL('**/orders/1/success')
  await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.equal(new Set(paymentCalls).size, 2)
  assert.deepEqual(await cart(page), [])
  await page.getByRole('button', { name: 'Copy key 1', exact: true }).click()
  await page.getByRole('button', { name: 'Copy key 1', exact: true }).filter({ hasText: 'Copied!' }).waitFor()
  const stored = await page.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]))
  assert.ok(!stored.includes('SYNTHETIC-DEMO-KEY'))
})
test('unknown success checks backend before retry and preserves newly added quantities', async t => {
  const { page, state, paymentCalls } = await setup(t)
  await checkout(page)
  await page.goto(base + '/cart'); await page.getByLabel('Cart total', { exact: true }).filter({ hasText: '€' }).waitFor()
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click()
  await page.goto(base + '/orders/1'); await page.getByRole('button', { name: 'Pay with demo card', exact: true }).waitFor()
  state.simulationLoss = true
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.getByRole('button', { name: 'Check payment status', exact: true }).click()
  await page.waitForURL('**/orders/1/success'); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.equal(paymentCalls.length, 1)
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 1 }])
  await page.reload(); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 1 }])
})
test('removing then readding the same offer after checkout preserves the new item', async t => {
  const { page } = await setup(t)
  await checkout(page)
  await page.goto(base + '/cart'); await page.locator('[data-offer-id="1"]').getByRole('button', { name: 'Remove item from cart', exact: true }).click()
  await page.goto(base + '/games/1'); await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await page.goto(base + '/orders/1'); await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.waitForURL('**/orders/1/success'); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 1 }])
})
test('expiry is checked on backend; payment stays disabled and cart is retained', async t => {
  const { page, state, paymentCalls } = await setup(t)
  state.expireSoon = true
  await checkout(page)
  await page.getByRole('status').filter({ hasText: 'Your reservation has expired.' }).waitFor()
  assert.equal(paymentCalls.length, 0)
  assert.deepEqual(await cart(page), [{ offerId: 1, quantity: 1 }])
})
test('keys error is retryable, stock conflict is friendly and mobile layout fits', async t => {
  const { page, state } = await setup(t)
  await page.setViewportSize({ width: 375, height: 812 })
  state.checkoutConflict = true
  await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: 'Stock or reservation changed' }).waitFor()
  assert.ok(!(await page.locator('main').innerText()).includes('SQLSTATE'))
  state.checkoutConflict = false; state.keysLoss = true
  await page.getByRole('button', { name: 'Retry checkout', exact: true }).click()
  await page.waitForURL('**/orders/1'); await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.waitForURL('**/orders/1/success'); await page.getByRole('button', { name: 'Retry loading keys', exact: true }).click()
  await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
})

test('double clicks cannot duplicate checkout/payment and later other offers remain', async t => {
  const { page, checkoutCalls, paymentCalls } = await setup(t)
  await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.waitForURL('**/orders/1')
  await page.goto(base + '/games/2'); await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await page.goto(base + '/orders/1')
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.waitForURL('**/orders/1/success'); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.equal(new Set(checkoutCalls.map(c => c.key)).size, 1)
  assert.equal(paymentCalls.length, 1)
  assert.deepEqual(await cart(page), [{ offerId: 2, quantity: 1 }])
  await page.getByRole('link', { name: 'Shopping cart, 1 item', exact: true }).waitFor()
})

test('lost payment-create response reloads pending attempt before resuming without another creation', async t => {
  const { page, state, paymentCalls } = await setup(t)
  await checkout(page)
  state.paymentLoss = true
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.getByRole('button', { name: 'Check payment status', exact: true }).waitFor()
  await page.reload()
  await page.getByRole('button', { name: 'Continue demo payment', exact: true }).click()
  await page.waitForURL('**/orders/1/success'); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
  assert.equal(paymentCalls.length, 1)
})
test('opening success URL before payment does not fetch or display keys', async t => {
  const { page, state } = await setup(t)
  await checkout(page)
  await page.goto(base + '/orders/1/success')
  await page.waitForURL('**/orders/1')
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).waitFor()
  assert.equal(state.keyRequests, 0)
  assert.equal(await page.locator('code').count(), 0)
})
