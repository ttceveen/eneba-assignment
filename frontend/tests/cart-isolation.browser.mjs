import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
const userA = { id: 1, first_name: 'Alice', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
const userB = { ...userA, id: 2, first_name: 'Bob' }
const approval = 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123'
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const test = (name, run) => browserTest(name, { timeout: 25000 }, run)
async function setup(t, options = {}) {
  const context = await browser.newContext()
  const state = { user: userA, meError: false, meHold: null, offersError: false, offersHold: null, gameHold: null, logoutError: false, stock: 5, ...options }
  const errors = [], calls = []
  await context.addInitScript(({ cart, owner }) => {
    if (!localStorage.getItem('isolation-fixture-seeded')) {
      localStorage.setItem('isolation-fixture-seeded', '1')
      if (cart.length) localStorage.setItem('eneba:cart', JSON.stringify(cart))
      if (owner !== null) localStorage.setItem('eneba:cart-owner', String(owner))
    }
    if (!sessionStorage.getItem('isolation-fixture-tab')) {
      sessionStorage.setItem('isolation-fixture-tab', '1')
      sessionStorage.setItem('eneba:auth-token', 'fixture-token')
    }
  }, { cart: options.cart ?? [{ offerId: 1, quantity: 3 }], owner: options.owner ?? 1 })
  const game = () => ({ id: 1, name: 'Isolation fixture game', img: null, location: ['GLOBAL'], min_price: '9.99', offers_count: 1, offers: [{ id: 1, price: '9.99', quantity: state.stock, seller: { id: 1, name: 'Fixture seller' } }] })
  const payment = { id: 1, order_id: 1, provider: 'paypal', external_id: 'ORDER123', redirect_url: approval, idempotency_key: 'fixture-key', status: 'pending', amount: '9.99', currency: 'EUR', error_code: null, error_message: null }
  const order = { id: 1, status: 'pending_payment', total_amount: '9.99', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(), items: [{ id: 1, offer_id: 1, quantity: 1, unit_price: '9.99', game: game(), seller: game().offers[0].seller }], payments: [payment] }
  const json = async (route, status, body) => {
    try { await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) }) }
    catch (error) { if (!/closed|cancel|abort|intercept|invalid/i.test(String(error))) throw error }
  }
  await context.route('https://www.sandbox.paypal.com/**', route => route.fulfill({ contentType: 'text/html', body: '<h1>Sandbox approval fixture</h1>' }))
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const request = route.request(), path = new URL(request.url()).pathname
    calls.push({ path, method: request.method() })
    if (path === '/api/me') {
      const user = state.user
      if (state.meHold) await state.meHold
      return json(route, state.meError ? 503 : 200, state.meError ? {} : { data: user })
    }
    if (path === '/api/logout') return json(route, state.logoutError ? 503 : 200, { message: state.logoutError ? 'Temporary fixture failure' : 'Logged out' })
    if (path === '/api/games/1') {
      const data = game()
      if (state.gameHold) await state.gameHold
      return json(route, 200, { data })
    }
    if (path === '/api/offers') {
      const data = [{ ...game().offers[0], game: game(), currency: 'EUR', available: true }]
      if (state.offersHold) await state.offersHold
      return json(route, state.offersError ? 503 : 200, { data })
    }
    if (path === '/api/orders/1') return json(route, 200, { data: order })
    if (path === '/api/payments/paypal/confirm') return json(route, 200, { data: payment })
    return json(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: { first: '', last: '', prev: null, next: null } })
  })
  const page = await context.newPage()
  page.setDefaultTimeout(7000)
  page.on('pageerror', error => errors.push(error.message))
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  return { page, context, state, calls }
}
const stored = page => page.evaluate(() => ({ cart: localStorage.getItem('eneba:cart'), owner: localStorage.getItem('eneba:cart-owner') }))
const badge = (page, count) => page.getByRole('link', { name: `Pirkinių krepšelis. Prekių: ${count}`, exact: true }).waitFor()
async function ready(page) {
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  await page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€' }).waitFor()
}
const focus = page => page.evaluate(() => dispatchEvent(new Event('focus')))

async function logout(page) {
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).click()
  await page.getByRole('link', { name: 'Prisijungti', exact: true }).waitFor()
  await badge(page, 0)
}

test('add item, Logout and reload clear React cart, storage and local add feedback', async t => {
  const { page } = await setup(t, { cart: [] })
  await page.goto(base + '/games/1')
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  await page.getByRole('button', { name: 'Į krepšelį', exact: true }).click()
  await page.getByText('Pridėta į krepšelį.', { exact: true }).waitFor()
  await badge(page, 1)
  await logout(page)
  assert.deepEqual(await stored(page), { cart: null, owner: null })
  assert.equal(await page.getByText('Pridėta į krepšelį.', { exact: true }).count(), 0)
  await page.goto(base + '/cart')
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  await page.reload()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  assert.deepEqual(await stored(page), { cart: null, owner: null })
})

test('confirmed A to B change clears cart and stock notices before showing B', async t => {
  const { page, state } = await setup(t)
  state.stock = 2
  await page.goto(base + '/cart')
  await ready(page)
  await page.getByRole('status').filter({ hasText: 'Kiekis pakeistas iš 3 į 2.' }).waitFor()
  await page.evaluate(() => {
    window.foreignCartFlashes = []
    new MutationObserver(() => {
      if (document.querySelector('[aria-label="Užsakymų istorija"]')?.textContent.includes('Bob') && document.querySelector('[data-offer-id]')) window.foreignCartFlashes.push(true)
    }).observe(document.body, { childList: true, subtree: true, characterData: true })
  })
  state.user = userB
  await focus(page)
  await page.getByRole('link', { name: 'Užsakymų istorija', exact: true }).filter({ hasText: 'Bob' }).waitFor()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  assert.deepEqual(await stored(page), { cart: null, owner: '2' })
  assert.equal(await page.getByRole('status').filter({ hasText: 'Pasikeitė turimas kiekis.' }).count(), 0)
  assert.deepEqual(await page.evaluate(() => window.foreignCartFlashes), [])
})

test('stored owner protects A cart when reload restores a different user B', async t => {
  const { page } = await setup(t, { user: userB })
  await page.goto(base + '/cart')
  await page.getByRole('link', { name: 'Užsakymų istorija', exact: true }).filter({ hasText: 'Bob' }).waitFor()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  assert.deepEqual(await stored(page), { cart: null, owner: '2' })
})

test('A reload and restoration of the same stable ID preserve the cart', async t => {
  const { page, state } = await setup(t)
  await page.goto(base + '/cart')
  await ready(page)
  const before = await stored(page)
  state.user = { ...userA, first_name: 'Renamed', email: 'renamed@example.test' }
  await page.reload()
  await ready(page)
  await badge(page, 3)
  assert.deepEqual(await stored(page), before)
  await page.getByRole('link', { name: 'Užsakymų istorija', exact: true }).filter({ hasText: 'Renamed' }).waitFor()
})

test('initial auth loading and protected-route revalidation never clear the cart', async t => {
  const { page, state } = await setup(t)
  let release
  state.meHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await page.goto(base + '/cart')
  await page.getByRole('link', { name: 'Prisijungti', exact: true }).filter({ hasText: 'Tikrinama…' }).waitFor()
  await badge(page, 3)
  assert.deepEqual(JSON.parse((await stored(page)).cart), [{ offerId: 1, quantity: 3 }])
  release()
  await ready(page)
  let finish
  state.meHold = new Promise(resolve => { finish = resolve })
  t.after(() => finish())
  await page.getByRole('link', { name: 'Užsakymų istorija', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Tikrinama paskyra…' }).waitFor()
  await badge(page, 3)
  finish()
  await page.getByRole('heading', { name: 'Užsakymų istorija', exact: true }).waitFor()
  assert.deepEqual(JSON.parse((await stored(page)).cart), [{ offerId: 1, quantity: 3 }])
})

test('temporary auth error and recovery keep the same user cart', async t => {
  const { page, state } = await setup(t, { meError: true })
  await page.goto(base + '/cart')
  await page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€' }).waitFor()
  await badge(page, 3)
  const before = await stored(page)
  state.meError = false
  await focus(page)
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  assert.deepEqual(await stored(page), before)
})

test('temporary offers API failure preserves cart and can be retried', async t => {
  const { page, state } = await setup(t, { offersError: true })
  await page.goto(base + '/cart')
  await page.getByRole('alert').filter({ hasText: 'Nepavyko įkelti krepšelio pasiūlymų.' }).waitFor()
  await badge(page, 3)
  const before = await stored(page)
  state.offersError = false
  await page.getByRole('button', { name: 'Bandyti dar kartą', exact: true }).click()
  await ready(page)
  assert.deepEqual(await stored(page), before)
})

test('PayPal external approval and cancelled/pending return preserve cart for the same user', async t => {
  const { page, calls } = await setup(t)
  await page.goto(base + '/orders/1')
  await page.getByRole('button', { name: 'Tęsti „PayPal“', exact: true }).waitFor()
  const before = await stored(page)
  await page.getByRole('button', { name: 'Tęsti „PayPal“', exact: true }).click()
  await page.waitForURL(approval)
  await page.goto(base + '/paypal/return?cancelled=1&token=ORDER123')
  await page.getByRole('heading', { name: '„PayPal“ mokėjimas nutrauktas', exact: true }).waitFor()
  assert.deepEqual(await stored(page), before)
  await page.goto(base + '/paypal/return?token=ORDER123')
  await page.getByRole('status').filter({ hasText: '„PayPal“ dar nepatvirtino mokėjimo.' }).waitFor()
  assert.deepEqual(await stored(page), before)
  assert.equal(calls.filter(call => call.path === '/api/payments/paypal/confirm').length, 1)
  assert.equal(calls.some(call => call.path.endsWith('/payments')), false)
})

test('old cart response after Logout cannot restore items or notices', async t => {
  const { page, state } = await setup(t)
  await page.goto(base + '/cart')
  await ready(page)
  let release
  state.stock = 1
  state.offersHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Įkeliami krepšelio pasiūlymai…' }).waitFor()
  await logout(page)
  release()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  assert.deepEqual(await stored(page), { cart: null, owner: null })
  assert.equal(await page.getByRole('status').filter({ hasText: 'Pasikeitė turimas kiekis.' }).count(), 0)
  await page.reload()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
})

test('old successful auth response after Logout cannot restore cart ownership', async t => {
  const { page, state } = await setup(t)
  await page.goto(base + '/cart')
  await ready(page)
  let release
  state.meHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await focus(page)
  await logout(page)
  release()
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  assert.deepEqual(await stored(page), { cart: null, owner: null })
  assert.equal(await page.getByRole('button', { name: 'Atsijungti', exact: true }).count(), 0)
})

test('failed Logout keeps cart; successful retry clears it', async t => {
  const { page, state } = await setup(t, { logoutError: true })
  await page.goto(base + '/cart')
  await ready(page)
  const before = await stored(page)
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).click()
  await page.getByRole('alert').filter({ hasText: 'Nepavyko atsijungti. Bandykite dar kartą.' }).waitFor()
  await badge(page, 3)
  assert.deepEqual(await stored(page), before)
  state.logoutError = false
  await logout(page)
  assert.deepEqual(await stored(page), { cart: null, owner: null })
})

test('a tab cannot adopt or overwrite a different cart owner through storage synchronization', async t => {
  const { page, context } = await setup(t)
  await page.goto(base + '/cart')
  await ready(page)
  const other = await context.newPage()
  await other.goto(base + '/')
  await other.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  await other.evaluate(() => {
    localStorage.setItem('eneba:cart-owner', '2')
    localStorage.setItem('eneba:cart', JSON.stringify([{ offerId: 2, quantity: 1 }]))
  })
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  await badge(page, 0)
  assert.deepEqual(await stored(page), { cart: '[{"offerId":2,"quantity":1}]', owner: '2' })
})
