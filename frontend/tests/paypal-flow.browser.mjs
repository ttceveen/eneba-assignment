import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
const approval = 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123'
const user = { id: 1, first_name: 'PayPal', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
const initialCart = [{ offerId: 1, quantity: 1 }]
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const test = (name, run) => browserTest(name, { timeout: 25000 }, run)
const json = (route, code, body) => route.fulfill({ status: code, contentType: 'application/json', body: JSON.stringify(body) })
async function setup(t, options = {}) {
  const context = await browser.newContext()
  const calls = [], errors = []
  const state = { confirm: 'succeeded', confirmError: null, hold: null, unpaid: false, redirect: approval, creationLoss: false, ...options }
  const order = { id: 1, status: 'pending_payment', total_amount: '9.99', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(),
    items: [{ id: 1, offer_id: 1, quantity: 1, unit_price: '9.99', game: { id: 1, name: 'PayPal Fixture', img: null }, seller: { id: 1, name: 'Fixture seller' } }], payments: [] }
  await context.addInitScript(({ origin, guest, initialCart }) => {
    if (location.origin !== origin || sessionStorage.getItem('paypal-fixture-init')) return
    sessionStorage.setItem('paypal-fixture-init', '1')
    if (!guest) sessionStorage.setItem('eneba:auth-token', 'fixture-token')
    localStorage.setItem('eneba:cart', JSON.stringify(initialCart))
    localStorage.setItem('eneba:cart-purchases', JSON.stringify([{ token: 'checkout-fixture', userId: 1, orderId: 1, remaining: initialCart, state: 'pending' }]))
    sessionStorage.setItem('eneba:checkout:1', JSON.stringify({ key: 'checkout-fixture', items: initialCart, orderId: 1, tracked: true }))
  }, { origin: new URL(base).origin, guest: options.guest ?? false, initialCart })
  const makePayment = (provider = 'paypal', key = 'fixture-key') => ({ id: 1, order_id: 1, provider, idempotency_key: key, status: 'pending', amount: '9.99', currency: 'EUR',
    external_id: provider === 'paypal' ? 'ORDER123' : 'fake_card_1', redirect_url: provider === 'paypal' ? state.redirect : null, error_code: null, error_message: null })
  await context.route('https://www.sandbox.paypal.com/**', route => route.fulfill({ contentType: 'text/html', body: '<title>Sandbox approval fixture</title><h1>Sandbox approval fixture</h1>' }))
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const req = route.request(), path = new URL(req.url()).pathname
    const call = { path, method: req.method(), body: req.postData() ? req.postDataJSON() : null, key: req.headers()['idempotency-key'], auth: req.headers().authorization }
    calls.push(call)
    if (path === '/api/me') return req.headers().authorization ? json(route, 200, { data: user }) : json(route, 401, {})
    if (path === '/api/login' || path === '/api/register') return json(route, 200, { data: user, token: 'fixture-token' })
    if (path === '/api/orders/1') return json(route, 200, { data: order })
    if (path === '/api/orders/1/payments') {
      let payment = order.payments.find(p => p.idempotency_key === call.key)
      if (!payment) { payment = makePayment(call.body.provider, call.key); order.payments = [payment] }
      if (state.creationLoss) { state.creationLoss = false; payment.external_id = null; payment.redirect_url = null; return json(route, 503, {}) }
      if (payment.provider === 'paypal') { payment.external_id = 'ORDER123'; payment.redirect_url = state.redirect }
      return json(route, 201, { data: payment })
    }
    if (path === '/api/payments/paypal/confirm') {
      if (state.hold) await state.hold
      if (state.confirmError === 'network') return route.abort('timedout')
      if (state.confirmError) return json(route, state.confirmError, { message: 'SQLSTATE private-provider-secret' })
      const payment = order.payments.find(p => p.provider === 'paypal') ?? makePayment()
      payment.status = state.confirm
      order.payments = [payment]
      if (payment.status === 'succeeded' && !state.unpaid) order.status = 'paid'
      return json(route, 200, { data: payment })
    }
    if (path === '/api/payments/1/simulate') {
      assert.equal(order.payments[0].provider, 'fake_card')
      order.payments[0].status = call.body.result === 'success' ? 'succeeded' : 'failed'
      if (order.payments[0].status === 'succeeded') order.status = 'paid'
      return json(route, 200, { data: order.payments[0] })
    }
    if (path === '/api/orders/1/keys') {
      assert.equal(order.status, 'paid')
      return json(route, 200, { data: { order_id: 1, items: [{ order_item_id: 1, offer_id: 1, keys: [{ id: 1, key_value: 'SYNTHETIC-PAYPAL-KEY' }] }] } })
    }
    if (path === '/api/orders/1/cancel') { order.status = 'cancelled'; return json(route, 200, { data: order }) }
    if (path === '/api/offers') return json(route, 200, { data: [{ id: 1, game: order.items[0].game, seller: order.items[0].seller, price: '9.99', quantity: 3, available: true, currency: 'EUR' }] })
    return json(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: {} })
  })
  const page = await context.newPage()
  page.on('pageerror', error => errors.push(error.message))
  page.setDefaultTimeout(7000)
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  return { page, context, calls, order, state, makePayment }
}
const cart = page => page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart')))
const confirms = calls => calls.filter(c => c.path === '/api/payments/paypal/confirm')
const creates = calls => calls.filter(c => c.path === '/api/orders/1/payments')
const repeat = page => page.getByRole('button', { name: 'Repeat payment check', exact: true })
const goReturn = page => page.goto(base + '/paypal/return?token=ORDER123')
async function choosePayPal(page) {
  await page.goto(base + '/orders/1')
  await page.getByLabel('PayPal Sandbox', { exact: true }).check()
}

test('PayPal selection creates one payment and redirects only to Sandbox without clearing cart or simulating', async t => {
  const { page, calls } = await setup(t)
  await choosePayPal(page)
  await page.getByRole('button', { name: 'Pay with PayPal Sandbox', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.waitForURL(approval)
  assert.equal(creates(calls).length, 1)
  assert.deepEqual(creates(calls)[0].body, { provider: 'paypal' })
  assert.ok(creates(calls)[0].key)
  assert.equal(confirms(calls).length, 0)
  assert.equal(calls.some(c => c.path.includes('/simulate')), false)
  await page.goto(base + '/paypal/return?cancelled=1&token=ORDER123')
  await page.getByRole('heading', { name: 'PayPal checkout interrupted' }).waitFor()
  assert.deepEqual(await cart(page), initialCart)
  await page.getByRole('link', { name: 'Back to checkout', exact: true }).click()
  await page.getByRole('button', { name: 'Continue PayPal Sandbox checkout', exact: true }).click()
  await page.waitForURL(approval)
  assert.equal(creates(calls).length, 1)
  assert.equal(confirms(calls).length, 0)
})
test('Fake Card stays selected by default and uses the existing simulator and Success', async t => {
  const { page, calls } = await setup(t)
  await page.goto(base + '/orders/1')
  assert.equal(await page.getByLabel('Fake Card', { exact: true }).isChecked(), true)
  await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
  await page.getByText('SYNTHETIC-PAYPAL-KEY', { exact: true }).waitFor()
  assert.deepEqual(creates(calls)[0].body, { provider: 'fake_card' })
  assert.equal(calls.filter(c => c.path.endsWith('/simulate')).length, 1)
  assert.equal(confirms(calls).length, 0)
})
test('return confirms the Order ID through backend and Success verifies paid before displaying keys', async t => {
  const { page, calls } = await setup(t)
  await goReturn(page)
  await page.getByText('SYNTHETIC-PAYPAL-KEY', { exact: true }).waitFor()
  assert.deepEqual(confirms(calls)[0].body, { paypal_order_id: 'ORDER123' })
  assert.equal(confirms(calls)[0].auth, 'Bearer fixture-token')
  assert.equal(creates(calls).length, 0)
  assert.ok(calls.findIndex(c => c.path === '/api/orders/1') < calls.findIndex(c => c.path.endsWith('/keys')))
  assert.deepEqual(await cart(page), [])
})
test('missing, invalid and duplicate tokens never call confirmation and preserve cart', async t => {
  const { page, calls } = await setup(t)
  for (const query of ['', '?token=', '?token=bad%2Fid', '?token=ORDER123&token=OTHER123']) {
    await page.goto(base + '/paypal/return' + query)
    await page.getByRole('alert').filter({ hasText: 'missing a valid Order ID' }).waitFor()
    assert.deepEqual(await cart(page), initialCart)
  }
  assert.equal(confirms(calls).length, 0)
})
test('pending preserves cart and manual retry uses the same ID without new attempts', async t => {
  const { page, calls, state } = await setup(t, { confirm: 'pending' })
  await goReturn(page)
  await page.getByRole('status').filter({ hasText: 'Платёж ещё обрабатывается' }).waitFor()
  assert.deepEqual(await cart(page), initialCart)
  assert.equal(confirms(calls).length, 1)
  state.confirm = 'succeeded'
  await repeat(page).click()
  await page.getByText('SYNTHETIC-PAYPAL-KEY', { exact: true }).waitFor()
  assert.deepEqual(confirms(calls).map(c => c.body), [{ paypal_order_id: 'ORDER123' }, { paypal_order_id: 'ORDER123' }])
  assert.equal(creates(calls).length, 0)
})
for (const error of ['network', 502, 404, 409]) test(`return error ${error} is safe, preserves cart and allows manual retry`, async t => {
  const { page, calls, state } = await setup(t, { confirmError: error })
  await goReturn(page)
  await repeat(page).waitFor()
  await page.getByRole('status').filter({ hasText: /Unable|not available|Stock/ }).waitFor()
  assert.equal((await page.textContent('body')).includes('private-provider-secret'), false)
  assert.deepEqual(await cart(page), initialCart)
  assert.equal(confirms(calls).length, 1)
  state.confirmError = null
  await repeat(page).click()
  await page.getByText('SYNTHETIC-PAYPAL-KEY', { exact: true }).waitFor()
  assert.equal(confirms(calls).length, 2)
})
test('double click on retry cannot duplicate an in-flight confirmation', async t => {
  const { page, calls, state } = await setup(t, { confirm: 'pending' })
  await goReturn(page)
  await page.getByRole('status').filter({ hasText: 'Платёж ещё обрабатывается' }).waitFor()
  let release
  state.hold = new Promise(resolve => { release = resolve })
  await repeat(page).evaluate(button => { button.click(); button.click() })
  await page.getByRole('button', { name: 'Checking payment...', exact: true }).waitFor()
  release()
  await page.getByRole('status').filter({ hasText: 'Платёж ещё обрабатывается' }).waitFor()
  assert.equal(confirms(calls).length, 2)
})
test('login and register preserve the PayPal return path and token', async t => {
  const { page, calls } = await setup(t, { guest: true, confirm: 'pending' })
  await goReturn(page)
  await page.waitForURL('**/login?redirect=*')
  assert.equal(new URL(page.url()).searchParams.get('redirect'), '/paypal/return?token=ORDER123')
  await page.getByRole('link', { name: 'Create an account' }).click()
  assert.equal(new URL(page.url()).searchParams.get('redirect'), '/paypal/return?token=ORDER123')
  await page.getByLabel('First name', { exact: true }).fill('PayPal')
  await page.getByLabel('Last name', { exact: true }).fill('Fixture')
  await page.getByLabel('Email', { exact: true }).fill('fixture@example.test')
  await page.getByLabel('Password', { exact: true }).fill('fixture-password')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Платёж ещё обрабатывается' }).waitFor()
  assert.equal(new URL(page.url()).pathname, '/paypal/return')
  assert.equal(confirms(calls).length, 1)
  assert.deepEqual(await cart(page), initialCart)
})
test('cancel return does not confirm or cancel automatically and goes back to the existing order', async t => {
  const { page, calls, order } = await setup(t)
  await page.goto(base + '/paypal/return?cancelled=1&token=ORDER123')
  await page.getByRole('heading', { name: 'PayPal checkout interrupted' }).waitFor()
  assert.equal(confirms(calls).length, 0)
  assert.equal(order.status, 'pending_payment')
  assert.deepEqual(await cart(page), initialCart)
  await page.getByRole('link', { name: 'Back to checkout', exact: true }).click()
  await page.waitForURL('**/orders/1')
  assert.equal(calls.some(c => c.path.endsWith('/cancel')), false)
})
test('backend unpaid order prevents key requests even if confirmation returned succeeded', async t => {
  const { page, calls } = await setup(t, { unpaid: true })
  await goReturn(page)
  await page.waitForURL('**/orders/1')
  await page.getByRole('heading', { name: 'Order #1', exact: true }).waitFor()
  assert.equal(calls.some(c => c.path.endsWith('/keys')), false)
  assert.deepEqual(await cart(page), initialCart)
})
test('existing PayPal attempt can be checked after the initial reservation deadline without another creation', async t => {
  const { page, calls, order, makePayment } = await setup(t, { confirm: 'pending' })
  order.payments = [makePayment()]
  order.reserved_until = new Date(Date.now() - 1000).toISOString()
  await page.goto(base + '/orders/1')
  await page.getByRole('link', { name: 'Check PayPal payment status', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Платёж ещё обрабатывается' }).waitFor()
  assert.equal(creates(calls).length, 0)
  assert.equal(confirms(calls).length, 1)
})
test('lost PayPal initialization retries the same pending attempt and idempotency key', async t => {
  const { page, calls } = await setup(t, { creationLoss: true })
  await choosePayPal(page)
  await page.getByRole('button', { name: 'Pay with PayPal Sandbox', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: 'Unable to confirm' }).waitFor()
  await page.getByRole('button', { name: 'Check payment status', exact: true }).click()
  await page.getByRole('button', { name: 'Pay with PayPal Sandbox', exact: true }).click()
  await page.waitForURL(approval)
  assert.equal(creates(calls).length, 2)
  assert.equal(creates(calls)[0].key, creates(calls)[1].key)
})
test('unsafe live PayPal redirect is rejected without navigation or confirmation', async t => {
  const { page, calls } = await setup(t, { redirect: 'https://www.paypal.com/checkoutnow?token=ORDER123' })
  await choosePayPal(page)
  await page.getByRole('button', { name: 'Pay with PayPal Sandbox', exact: true }).click()
  await page.getByRole('alert').waitFor()
  assert.equal(new URL(page.url()).origin, new URL(base).origin)
  assert.equal(confirms(calls).length, 0)
  assert.deepEqual(await cart(page), initialCart)
})

test('expired Bearer token returns through login to the same PayPal confirmation', async t => {
  const { page, state, calls } = await setup(t, { confirmError: 401 })
  await goReturn(page)
  await page.waitForURL('**/login?redirect=*')
  assert.equal(new URL(page.url()).searchParams.get('redirect'), '/paypal/return?token=ORDER123')
  assert.deepEqual(await cart(page), initialCart)
  state.confirmError = null
  await page.getByLabel('Email', { exact: true }).fill('fixture@example.test')
  await page.getByLabel('Password', { exact: true }).fill('fixture-password')
  await page.getByRole('button', { name: 'Log in', exact: true }).click()
  await page.getByText('SYNTHETIC-PAYPAL-KEY', { exact: true }).waitFor()
  assert.deepEqual(confirms(calls).map(c => c.body), [{ paypal_order_id: 'ORDER123' }, { paypal_order_id: 'ORDER123' }])
  assert.equal(creates(calls).length, 0)
})
