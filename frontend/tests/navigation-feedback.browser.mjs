import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const test = (name, run) => browserTest(name, { timeout: 25000 }, run)
async function setup(t, options = {}) {
  const context = await browser.newContext({ viewport: { width: options.width ?? 1280, height: 844 } })
  const state = { stock: 5, catalogError: false, gameError: false, catalogHold: null, gameHold: null }
  const calls = [], errors = []
  const game = id => ({ id, name: `Game ${id}`, img: '/images/cyberpunk.jpg', location: ['GLOBAL'], min_price: '9.99', offers_count: 1, offers: [{ id, price: '9.99', quantity: state.stock, seller: { id: 1, name: 'Fixture seller' } }] })
  const order = { id: 1, status: 'pending_payment', total_amount: '9.99', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(), items: [{ id: 1, offer_id: 1, quantity: 1, unit_price: '9.99', game: game(1), seller: game(1).offers[0].seller }], payments: [] }
  await context.addInitScript(saved => {
    if (sessionStorage.getItem('navigation-fixture')) return
    sessionStorage.setItem('navigation-fixture', '1')
    sessionStorage.setItem('eneba:auth-token', 'fixture-token')
    localStorage.setItem('eneba:cart', JSON.stringify(saved))
  }, options.cart ?? [])
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname
    calls.push({ path, query: url.search, method: request.method() })
    let data, status = 200
    if (path === '/api/me') data = { id: 1, first_name: 'Player', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
    else if (path === '/api/games' || path === '/api/games/search') {
      if (state.catalogHold) await state.catalogHold
      if (state.catalogError) return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
      const data = Array.from({ length: 12 }, (_, i) => game(i + 1))
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(url.searchParams.has('preview') ? { data: data.slice(0, 2), suggestions: ['Game'] } : { data, meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 3, per_page: 12, total: 36 }, links: { first: '', last: '', prev: '', next: '' } }) })
    } else if (/^\/api\/games\/\d+$/.test(path)) {
      data = game(Number(path.split('/').at(-1)))
      if (state.gameHold) await state.gameHold
      if (state.gameError) status = 503
    } else if (path === '/api/offers') data = url.searchParams.get('ids').split(',').map(Number).map(id => ({ ...game(id).offers[0], game: game(id), currency: 'EUR', available: true }))
    else if (path === '/api/orders/1') data = order
    else return route.abort('blockedbyclient')
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ data }) })
  })
  const page = await context.newPage()
  page.setDefaultTimeout(7000)
  page.on('pageerror', error => errors.push(error.message))
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  return { page, context, calls, state, order }
}
const card = (page, id = 1) => page.getByRole('link', { name: `Game ${id}`, exact: true })
const cartReady = page => page.getByLabel('Cart total', { exact: true }).filter({ hasText: '€' }).waitFor()
const notices = page => page.getByRole('status').filter({ hasText: 'Available stock changed.' })
const quantity = page => page.getByLabel('Quantity for offer 1', { exact: true })
const matching = (calls, path) => calls.filter(call => call.path === path)

for (const query of ['?sort=alphabetical&page=2', '?q=Game&page=2', '?q=Game&sort=alphabetical&page=3']) test(`game return preserves catalog ${query}`, async t => {
  const { page } = await setup(t)
  await page.goto(base + '/' + query)
  await card(page, 7).scrollIntoViewIfNeeded()
  const before = await page.locator('[data-game-id="7"]').evaluate(e => e.getBoundingClientRect().top)
  await card(page, 7).click()
  await page.waitForURL('**/games/7')
  await page.getByRole('heading', { name: 'Game 7', exact: true, level: 1 }).waitFor()
  assert.equal(await page.getByRole('link', { name: '← Games', exact: true }).getAttribute('href'), '/' + query)
  await page.getByRole('link', { name: '← Games', exact: true }).click()
  await page.waitForURL(base + '/' + query)
  await card(page, 7).waitFor()
  const after = await page.locator('[data-game-id="7"]').evaluate(e => e.getBoundingClientRect().top)
  assert.ok(Math.abs(after - before) < 4, 'return also restores catalog position')
})

test('direct game entry ignores an unrelated stored catalog', async t => {
  const { page } = await setup(t)
  await page.goto(base + '/?sort=alphabetical&page=2')
  await card(page).waitFor()
  await page.goto(base + '/games/1')
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(await page.getByRole('link', { name: '← Games', exact: true }).getAttribute('href'), '/')
})

test('clear search still returns to the original non-search catalog after a game visit', async t => {
  const { page } = await setup(t)
  const catalog = base + '/?sort=alphabetical&page=2'
  await page.goto(catalog)
  await card(page).waitFor()
  await page.getByRole('searchbox', { name: 'Search games', exact: true }).click()
  await page.locator('#search-query').fill('Game')
  await page.getByRole('button', { name: 'Search', exact: true }).click()
  await page.waitForURL('**/?q=Game')
  await card(page).click()
  await page.waitForURL('**/games/1')
  await page.getByRole('link', { name: '← Games', exact: true }).click()
  await page.waitForURL('**/?q=Game')
  await page.getByRole('button', { name: 'Clear search', exact: true }).click()
  await page.waitForURL(catalog)
})

for (const query of ['?sort=alphabetical&page=2', '?q=Game&page=2']) test(`catalog Retry sends one GET and retains ${query}`, async t => {
  const { page, calls, state } = await setup(t, { width: 390 })
  state.catalogError = true
  await page.goto(base + '/' + query)
  await page.getByRole('alert').filter({ hasText: 'Failed to load games.' }).waitFor()
  const path = query.includes('q=') ? '/api/games/search' : '/api/games'
  assert.equal(matching(calls, path).length, 1)
  const documentRequests = []
  page.on('request', request => { if (request.isNavigationRequest()) documentRequests.push(request.url()) })
  let release
  state.catalogHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  state.catalogError = false
  await page.getByRole('button', { name: 'Retry', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.getByRole('status').filter({ hasText: 'Loading games...' }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Retry', exact: true }).count(), 0)
  release()
  await card(page).waitFor()
  assert.equal(matching(calls, path).length, 2)
  assert.equal(matching(calls, path)[0].query, matching(calls, path)[1].query)
  assert.equal(page.url(), base + '/' + query)
  assert.deepEqual(documentRequests, [])
  assert.equal(calls.some(call => call.method !== 'GET'), false)
})

test('game Retry retains return context and only repeats the game GET', async t => {
  const { page, calls, state } = await setup(t)
  state.gameError = true
  await page.goto(base + '/?q=Game&page=2')
  await card(page).click()
  await page.getByRole('alert').filter({ hasText: 'Failed to load game.' }).waitFor()
  assert.equal(matching(calls, '/api/games/1').length, 1)
  const catalogCount = matching(calls, '/api/games/search').length
  let release
  state.gameHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  state.gameError = false
  await page.getByRole('button', { name: 'Retry', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.getByRole('status').filter({ hasText: 'Loading game...' }).waitFor()
  release()
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(matching(calls, '/api/games/1').length, 2)
  assert.equal(matching(calls, '/api/games/search').length, catalogCount)
  assert.equal(await page.getByRole('link', { name: '← Games', exact: true }).getAttribute('href'), '/?q=Game&page=2')
  await page.getByRole('heading', { name: 'Seller offers', exact: true }).waitFor()
  await page.getByText('Available: 5', { exact: true }).waitFor()
})

test('automatic stock reduction is announced once; manual quantity edits never announce it', async t => {
  const { page, state } = await setup(t, { width: 360, cart: [{ offerId: 1, quantity: 3 }] })
  await page.goto(base + '/cart')
  await cartReady(page)
  assert.equal(await notices(page).count(), 0)
  state.stock = 2
  await page.getByRole('button', { name: 'Refresh prices', exact: true }).click()
  await quantity(page).filter({ hasText: '2' }).waitFor()
  await notices(page).filter({ hasText: 'Quantity updated from 3 to 2.' }).waitFor()
  assert.equal(await notices(page).count(), 1)
  await page.getByLabel('Mobile cart total', { exact: true }).filter({ hasText: '€19.98' }).waitFor()
  await page.getByRole('button', { name: 'Refresh prices', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 1)
  await page.getByRole('button', { name: 'Decrease quantity for offer 1', exact: true }).click()
  await quantity(page).filter({ hasText: '1' }).waitFor()
  assert.equal(await notices(page).count(), 0)
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click()
  await quantity(page).filter({ hasText: '2' }).waitFor()
  assert.equal(await notices(page).count(), 0)
  await page.getByRole('button', { name: 'Refresh prices', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 0)
})

test('stock reduction while loading a game is announced there and retained in the cart', async t => {
  const { page, state } = await setup(t, { cart: [{ offerId: 1, quantity: 3 }] })
  state.stock = 2
  await page.goto(base + '/games/1')
  await notices(page).filter({ hasText: 'Quantity updated from 3 to 2.' }).waitFor()
  await page.getByRole('link', { name: 'Shopping cart, 2 items', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 1)
  await page.getByRole('button', { name: 'Refresh prices', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 1)
})

test('stale game response cannot announce a stock reduction that was not applied', async t => {
  const { page, context, state } = await setup(t, { cart: [{ offerId: 1, quantity: 3 }] })
  const other = await context.newPage()
  await other.goto(base + '/')
  state.stock = 2
  let release
  state.gameHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await page.goto(base + '/games/1')
  await page.getByRole('status').filter({ hasText: 'Loading game...' }).waitFor()
  await other.evaluate(() => localStorage.setItem('eneba:cart', JSON.stringify([{ offerId: 1, quantity: 4 }])))
  await page.getByRole('link', { name: 'Shopping cart, 4 items', exact: true }).waitFor()
  release()
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(await notices(page).count(), 0)
  assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))), [{ offerId: 1, quantity: 4 }])
})

test('pending payment explains locked method without unlocking it or confirming automatically', async t => {
  const { page, order, calls } = await setup(t, { width: 390 })
  order.payments = [{ id: 1, order_id: 1, provider: 'paypal', external_id: 'ORDER123', redirect_url: 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', idempotency_key: 'fixture-key', status: 'pending', amount: '9.99', currency: 'EUR', error_code: null, error_message: null }]
  await page.goto(base + '/orders/1')
  await page.getByText('Payment method is locked for the current payment attempt.', { exact: true }).waitFor()
  assert.equal(await page.getByLabel('Fake Card', { exact: true }).isDisabled(), true)
  assert.equal(await page.getByLabel('PayPal Sandbox', { exact: true }).isDisabled(), true)
  assert.equal(await page.getByLabel('PayPal Sandbox', { exact: true }).isChecked(), true)
  assert.equal(await page.getByRole('button', { name: 'Confirm PayPal payment', exact: true }).count(), 1)
  assert.equal(await page.getByRole('button', { name: 'Continue in PayPal', exact: true }).count(), 1)
  assert.equal(calls.some(call => call.method !== 'GET'), false)
  assert.equal(await page.getByRole('button', { name: 'Order details', exact: true }).getAttribute('aria-expanded'), 'false')
})
