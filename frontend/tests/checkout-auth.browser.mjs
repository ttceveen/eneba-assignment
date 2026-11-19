import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const modulePath = process.env.PLAYWRIGHT_MODULE_PATH
const { chromium } = await import(modulePath ? pathToFileURL(modulePath).href : 'playwright')
const base = (process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173').replace(/\/$/, '')
const cartKey = 'eneba:cart', authKey = 'eneba:auth-token'
const user = { id: 1, first_name: 'Checkout', last_name: 'Fixture', email: 'checkout@example.test', status: 'active' }
const sample = [{ offerId: 2, quantity: 2 }, { offerId: 1, quantity: 1 }]
const test = (name, run) => browserTest(name, { timeout: 20000 }, run)
const pause = (ms = 120) => new Promise((resolve) => setTimeout(resolve, ms))
const json = (route, status, payload) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(payload) })
const me = (route) => json(route, 200, { data: user })
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
function held() {
  let start, release
  const started = new Promise((resolve) => { start = resolve })
  const gate = new Promise((resolve) => { release = resolve })
  return { started, release, handle: async (route, payload, status = 200) => {
    start(); await gate
    try { await json(route, status, payload) } catch (error) { if (!/closed|abort|cancel|invalid|intercept/i.test(String(error))) throw error }
  } }
}
async function setup(t, { authorized = false, cart = sample } = {}) {
  const context = await browser.newContext()
  const errors = [], urls = [], pending = []
  const api = {
    me, meCalls: 0, loginCalls: 0,
    login: (route) => json(route, 200, { data: user, token: 'checkout-fixture-token', token_type: 'Bearer' }),
    register: (route) => json(route, 201, { data: user, token: 'checkout-fixture-token', token_type: 'Bearer' }),
  }
  await context.route(/^https?:\/\/[^/]+\/api\//, async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname === '/api/me') { api.meCalls++; await api.me(route) }
    else if (url.pathname === '/api/login') { api.loginCalls++; await api.login(route) }
    else if (url.pathname === '/api/register') await api.register(route)
    // Keep these auth-boundary fixtures on checkout; purchasing is covered separately.
    else if (url.pathname === '/api/checkout') await json(route, 409, { message: 'Fixture stock is unavailable.' })
    else if (url.pathname === '/api/offers') {
      const ids = (url.searchParams.get('ids') ?? '').split(',').filter(Boolean).map(Number)
      await json(route, 200, { data: ids.map((id) => ({ id, game: { id, name: `Game ${id}`, img: null }, seller: { id, name: `Seller ${id}` }, price: '9.99', currency: 'EUR', quantity: 9, available: true })) })
    } else if (url.pathname === '/api/games') await json(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: { first: '', last: '', prev: null, next: null } })
    else if (url.pathname === '/api/promotions') await json(route, 200, { data: [] })
    else throw new Error(`Unexpected API route: ${url.pathname}`)
  })
  const page = await context.newPage(); page.setDefaultTimeout(5000)
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('framenavigated', (frame) => {
    if (frame !== page.mainFrame()) return
    const path = new URL(frame.url()).pathname
    // StrictMode may replace the same URL twice; a redirect loop alternates paths.
    if (urls.at(-1) !== path) urls.push(path)
  })
  t.after(async () => { for (const response of pending) response.release(); await context.close(); assert.deepEqual(errors, []) })
  await page.goto(base + '/cart')
  await page.getByRole('heading', { name: 'Jūsų krepšelis tuščias', exact: true }).waitFor()
  await page.evaluate(({ cartKey, authKey, cart, authorized }) => {
    localStorage.setItem(cartKey, JSON.stringify(cart))
    if (authorized) sessionStorage.setItem(authKey, 'checkout-fixture-token')
  }, { cartKey, authKey, cart, authorized })
  await page.reload()
  await page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€' }).waitFor()
  if (authorized) await page.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  async function unchanged() {
    assert.deepEqual(await page.evaluate((key) => JSON.parse(localStorage.getItem(key)), cartKey), cart)
  }
  async function checkout() {
    await page.getByRole('heading', { name: 'Užsakymo įforminimas', exact: true }).waitFor()
    assert.equal(new URL(page.url()).pathname, '/checkout')
    await page.getByRole('banner').waitFor()
    await page.getByRole('contentinfo').waitFor()
    const count = cart.reduce((sum, item) => sum + item.quantity, 0)
    await page.getByRole('link', { name: `Pirkinių krepšelis. Prekių: ${count}`, exact: true }).waitFor()
    await unchanged()
  }
  async function loginPage() {
    await page.waitForURL('**/login?redirect=*')
    assert.equal(new URL(page.url()).searchParams.get('redirect'), '/checkout')
    await unchanged()
  }
  async function clickCheckout() {
    await page.getByRole('button', { name: 'Įforminti užsakymą', exact: true }).click()
  }
  async function credentials() {
    await page.getByLabel('El. paštas', { exact: true }).fill(user.email)
    await page.getByLabel('Slaptažodis', { exact: true }).fill('CheckoutFixture123')
  }
  function holdMe() {
    const response = held(); pending.push(response)
    api.me = (route) => response.handle(route, { data: user })
    return response
  }
  return { page, api, urls, pending, checkout, unchanged, loginPage, clickCheckout, credentials, holdMe }
}

test('guest Checkout -> login -> successful login -> checkout with identical cart', async (t) => {
  const f = await setup(t)
  await f.clickCheckout(); await f.loginPage(); await f.credentials()
  await f.page.getByRole('button', { name: 'Prisijungti', exact: true }).click(); await f.checkout()
  await pause(); assert.equal(f.urls.filter((path) => path === '/login').length, 1)
  await f.page.getByRole('link', { name: 'Grįžti į krepšelį', exact: true }).click()
  await f.page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€' }).waitFor()
  assert.equal(await f.page.getByLabel('Pasiūlymo kiekis 2', { exact: true }).innerText(), '2')
  await f.unchanged()
})

test('login/register links preserve checkout destination and successful registration preserves cart', async (t) => {
  const f = await setup(t)
  await f.clickCheckout(); await f.loginPage()
  await f.page.getByRole('link', { name: 'Sukurti paskyrą', exact: true }).click()
  assert.equal(new URL(f.page.url()).searchParams.get('redirect'), '/checkout')
  await f.page.getByRole('link', { name: 'Jau turite paskyrą? Prisijunkite', exact: true }).click(); await f.loginPage()
  await f.page.getByRole('link', { name: 'Sukurti paskyrą', exact: true }).click()
  await f.page.getByLabel('Vardas', { exact: true }).fill('Užsakymo įforminimas')
  await f.page.getByLabel('Pavardė', { exact: true }).fill('Fixture')
  await f.credentials(); await f.page.getByRole('button', { name: 'Sukurti paskyrą', exact: true }).click()
  await f.checkout(); await pause(); assert.equal(new URL(f.page.url()).pathname, '/checkout')
})

test('authorized user opens checkout without login redirect, including full 20-position cart', async (t) => {
  const cart = Array.from({ length: 20 }, (_, i) => ({ offerId: i + 1, quantity: i === 0 ? 2 : 1 }))
  const f = await setup(t, { authorized: true, cart })
  await f.clickCheckout(); await f.checkout()
  assert.ok(!f.urls.includes('/login'))
  assert.equal(f.api.loginCalls, 0)
})

test('wrong password stays on login and does not lose cart or destination', async (t) => {
  const f = await setup(t)
  f.api.login = (route) => json(route, 422, { message: 'The provided credentials are incorrect.', errors: { email: ['The provided credentials are incorrect.'] } })
  await f.clickCheckout(); await f.loginPage(); await f.credentials()
  await f.page.getByRole('button', { name: 'Prisijungti', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Neteisingas el. pašto adresas arba slaptažodis.' }).waitFor()
  await f.loginPage(); assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), authKey), null)
})

test('registration validation failure keeps checkout destination and cart', async (t) => {
  const f = await setup(t)
  f.api.register = (route) => json(route, 422, { message: 'The email has already been taken.', errors: { email: ['The email has already been taken.'] } })
  await f.clickCheckout(); await f.loginPage()
  await f.page.getByRole('link', { name: 'Sukurti paskyrą', exact: true }).click()
  await f.page.getByLabel('Vardas', { exact: true }).fill('Užsakymo įforminimas'); await f.page.getByLabel('Pavardė', { exact: true }).fill('Fixture')
  await f.credentials(); await f.page.getByRole('button', { name: 'Sukurti paskyrą', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Šis el. pašto adresas jau naudojamas.' }).waitFor()
  assert.equal(new URL(f.page.url()).pathname, '/register'); assert.equal(new URL(f.page.url()).searchParams.get('redirect'), '/checkout')
  await f.unchanged()
})

test('expired token/me401 clears token, returns to login, and re-login returns to checkout once', async (t) => {
  const f = await setup(t, { authorized: true })
  f.api.me = (route) => json(route, 401, { message: 'Unauthenticated.' })
  await f.clickCheckout(); await f.loginPage()
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), authKey), null)
  f.api.me = me
  await f.credentials(); await f.page.getByRole('button', { name: 'Prisijungti', exact: true }).click(); await f.checkout()
  await pause(); assert.equal(f.urls.filter((path) => path === '/login').length, 1)
})

test('pending me does not prematurely redirect an authorized user', async (t) => {
  const f = await setup(t, { authorized: true })
  const pending = f.holdMe()
  await f.clickCheckout(); await pending.started
  await f.page.getByText('Tikrinama paskyra…', { exact: true }).waitFor()
  await pause(); assert.equal(new URL(f.page.url()).pathname, '/checkout'); assert.ok(!f.urls.includes('/login'))
  pending.release(); await f.checkout()
})

for (const networkFailure of [false, true]) test(`temporary me ${networkFailure ? 'network failure' : '503'} shows retry without login loop or cart loss`, async (t) => {
  const f = await setup(t, { authorized: true })
  const before = f.api.meCalls
  f.api.me = networkFailure ? (route) => route.abort('failed') : (route) => json(route, 503, { message: 'Temporary failure.' })
  await f.clickCheckout(); await f.page.getByRole('button', { name: 'Patikrinti paskyrą dar kartą', exact: true }).waitFor()
  await f.unchanged(); assert.equal(new URL(f.page.url()).pathname, '/checkout'); assert.ok(!f.urls.includes('/login'))
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), authKey), 'checkout-fixture-token')
  await pause(); assert.equal(f.api.meCalls - before, 1)
  f.api.me = me
  await f.page.getByRole('button', { name: 'Patikrinti paskyrą dar kartą', exact: true }).click(); await f.checkout()
})

test('cancel sign-in returns to cart without clearing offers or changing quantities', async (t) => {
  const f = await setup(t)
  await f.clickCheckout(); await f.loginPage()
  await f.page.getByRole('link', { name: 'Atšaukti ir grįžti į krepšelį', exact: true }).click()
  await f.page.waitForURL(base + '/cart'); await f.unchanged()
})

test('cancel pending login ignores later success and retains guest cart', async (t) => {
  const f = await setup(t)
  const pending = held(); f.pending.push(pending)
  f.api.login = (route) => pending.handle(route, { data: user, token: 'checkout-fixture-token', token_type: 'Bearer' })
  await f.clickCheckout(); await f.loginPage(); await f.credentials()
  await f.page.getByRole('button', { name: 'Prisijungti', exact: true }).click(); await pending.started
  await f.page.getByRole('link', { name: 'Atšaukti ir grįžti į krepšelį', exact: true }).click(); await f.page.waitForURL(base + '/cart')
  pending.release(); await pause(); await f.unchanged()
  assert.equal(new URL(f.page.url()).pathname, '/cart'); assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), authKey), null)
})


test('auth screens have standalone navigation and retain keyboard, loading and validation states', async t => {
  const { page, api, pending, credentials } = await setup(t)
  await page.setViewportSize({ width: 320, height: 700 })
  await page.goto(base + '/login?redirect=%2Fcheckout')
  const main = page.getByRole('main')
  const home = main.getByRole('link', { name: 'Eneba pradžia', exact: true })
  const email = main.getByLabel('El. paštas', { exact: true })
  const password = main.getByLabel('Slaptažodis', { exact: true })
  await home.focus()
  await page.keyboard.press('Tab')
  assert.ok(await email.evaluate(input => input.matches(':focus-visible') && getComputedStyle(input).outlineStyle === 'solid'))
  await page.keyboard.press('Shift+Tab')
  assert.ok(await home.evaluate(link => link === document.activeElement))
  await page.keyboard.press('Tab')
  await page.keyboard.press('Tab')
  assert.ok(await password.evaluate(input => input === document.activeElement))

  const longError = 'Email validation error: ' + 'fixture'.repeat(30)
  const response = held()
  pending.push(response)
  api.login = async route => {
    await response.handle(route, { message: longError, errors: { email: [longError] } }, 422)
  }
  await credentials()
  await password.press('Enter')
  await response.started
  assert.equal(await main.getByRole('button', { name: 'Palaukite…' }).isDisabled(), true)
  assert.equal(await email.isDisabled(), true)
  response.release()
  await main.getByRole('alert').waitFor()
  assert.equal(await email.isEnabled(), true)

  await email.and(page.locator('[aria-invalid="true"]')).waitFor()
  assert.equal(await email.getAttribute('aria-describedby'), 'email-error')
  assert.equal(await main.getByRole('alert').textContent(), 'Patikrinkite įvestus duomenis ir bandykite dar kartą.')
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 320)
  assert.equal(await page.getByRole('banner').count(), 0)
  assert.equal(await page.getByRole('contentinfo').count(), 0)

  await main.getByRole('link', { name: 'Sukurti paskyrą', exact: true }).click()
  await main.getByRole('heading', { name: 'Sukurti paskyrą', exact: true }).waitFor()
  assert.equal(new URL(page.url()).searchParams.get('redirect'), '/checkout')
  assert.deepEqual(await main.locator('form input').evaluateAll(inputs => inputs.map(input => input.name)), ['first_name', 'last_name', 'email', 'password'])
  assert.equal(await page.getByRole('banner').count(), 0)
  assert.equal(await page.getByRole('contentinfo').count(), 0)
  await home.click()
  await page.getByRole('banner').waitFor()
  await page.getByRole('contentinfo').waitFor()
})
