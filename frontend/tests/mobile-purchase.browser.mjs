import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const test = (name, run) => browserTest(name, { timeout: 25000 }, run)
async function setup(t, width) {
  const context = await browser.newContext({ viewport: { width, height: 844 } })
  const games = Array.from({ length: 4 }, (_, i) => ({ id: i + 1, name: `Game ${i + 1}`, img: '/images/cyberpunk.jpg', location: ['GLOBAL'], offers: [{ id: i + 1, seller: { id: 1, name: 'Fixture seller' }, price: '9.99', quantity: 5 }] }))
  const cart = games.map(game => ({ offerId: game.id, quantity: 1 }))
  const order = { id: 1, status: 'pending_payment', total_amount: '39.96', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(), items: games.map(game => ({ id: game.id, offer_id: game.id, quantity: 1, unit_price: '9.99', game, seller: game.offers[0].seller })), payments: [] }
  const state = { offersError: false, unavailable: false, offersHold: null, orderError: false, orderHold: null }
  const calls = [], errors = []
  await context.addInitScript(cart => {
    if (sessionStorage.getItem('mobile-fixture')) return
    sessionStorage.setItem('mobile-fixture', '1')
    sessionStorage.setItem('eneba:auth-token', 'fixture-token')
    localStorage.setItem('eneba:cart', JSON.stringify(cart))
  }, cart)
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const request = route.request(), url = new URL(request.url())
    calls.push({ path: url.pathname, method: request.method() })
    let data, status = 200
    if (url.pathname === '/api/me') data = { id: 1, first_name: 'Player', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
    else if (url.pathname === '/api/offers') {
      if (state.offersHold) await state.offersHold
      if (state.offersError) status = 503
      data = url.searchParams.get('ids').split(',').map(Number).map(id => ({ ...games[id - 1].offers[0], game: games[id - 1], currency: 'EUR', available: !state.unavailable }))
    } else if (url.pathname === '/api/orders/1') {
      if (state.orderHold) await state.orderHold
      if (state.orderError) status = 503
      data = order
    } else if (url.pathname === '/api/checkout') { data = order; status = 201 }
    else if (url.pathname === '/api/games/1') data = games[0]
    else return route.abort('blockedbyclient')
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ data }) })
  })
  const page = await context.newPage()
  page.setDefaultTimeout(7000)
  page.on('pageerror', error => errors.push(error.message))
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  return { page, state, order, calls }
}
const fits = page => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)
const rect = locator => locator.evaluate(element => { const r = element.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, width: r.width, height: r.height } })
for (const width of [360, 390, 768, 1024, 1440]) test(`purchase layout and details disclosure at ${width}px`, async t => {
  const { page, calls } = await setup(t, width)
  await page.goto(base + '/orders/1')
  const pay = page.getByRole('button', { name: 'Mokėti bandomąja kortele', exact: true })
  await pay.waitFor()
  assert.equal(await pay.count(), 1)
  const details = page.getByRole('button', { name: 'Užsakymo informacija', exact: true })
  if (width <= 900) {
    assert.equal(await details.getAttribute('aria-expanded'), 'false')
    assert.equal(await page.getByRole('region', { name: 'Užsakymo informacija', exact: true }).count(), 0)
    assert.ok((await rect(pay)).bottom < 844, 'payment action is in the first screen even with four items')
    const controlled = page.locator('#' + await details.getAttribute('aria-controls'))
    assert.equal(await controlled.isVisible(), false)
    await details.focus()
    await page.keyboard.press('Enter')
    assert.equal(await details.getAttribute('aria-expanded'), 'true')
    await page.getByRole('heading', { name: 'Užsakymas Nr. 1', exact: true }).waitFor()
    assert.equal(await page.getByText('Game 4', { exact: true }).isVisible(), true)
    await details.click()
    assert.equal(await controlled.isVisible(), false)
    await page.getByLabel('PayPal Sandbox', { exact: true }).check()
    const paypal = page.getByRole('button', { name: 'Mokėti per „PayPal Sandbox“', exact: true })
    assert.equal(await paypal.count(), 1)
    assert.ok((await rect(paypal)).top < (await rect(page.getByRole('timer'))).top)
  } else {
    assert.equal(await details.isVisible(), false)
    const summary = await rect(page.getByRole('region', { name: 'Užsakymo informacija', exact: true }))
    const method = await rect(page.getByLabel('Fake Card', { exact: true }))
    assert.ok(summary.right < method.left, 'desktop keeps order details on the left')
    await page.getByRole('heading', { name: 'Užsakymas Nr. 1', exact: true }).waitFor()
  }
  assert.ok(await fits(page))
  assert.equal(calls.some(c => c.method !== 'GET'), false, 'disclosure and selection must not start payments')
  await page.goto(base + '/cart')
  await page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€39.96' }).waitFor()
  const checkout = page.getByRole('button', { name: 'Įforminti užsakymą', exact: true })
  assert.equal(await checkout.count(), 1)
  const bar = page.getByLabel('Užsakymo veiksmai', { exact: true })
  const plus = page.getByRole('button', { name: 'Padidinti pasiūlymo kiekį 1', exact: true })
  if (width <= 800) {
    const box = await rect(plus)
    assert.ok(box.width >= 44 && box.height >= 44)
    assert.equal(await bar.evaluate(e => getComputedStyle(e).position), 'fixed')
    assert.ok((await rect(checkout)).bottom <= 844)
    await plus.click()
    await page.getByLabel('Krepšelio suma mobiliajame įrenginyje', { exact: true }).filter({ hasText: '€49.95' }).waitFor()
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
    const lastItem = await rect(page.locator('[data-offer-id="4"]'))
    assert.ok(lastItem.bottom < (await rect(bar)).top, 'bottom spacing keeps the last item and its controls above the bar')
    assert.equal(await bar.evaluate(e => Number.parseFloat(getComputedStyle(e).paddingBottom) >= 12), true)
  } else assert.notEqual(await bar.evaluate(e => getComputedStyle(e).position), 'fixed')
  assert.ok(await fits(page))
  await checkout.evaluate(button => { button.click(); button.click() })
  await page.waitForURL('**/orders/1')
  assert.equal(calls.filter(c => c.path === '/api/checkout').length, 1)
})

test('mobile cart dock shares loading, error and unavailable disabled states', async t => {
  const { page, state } = await setup(t, 360)
  let release
  state.offersHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await page.goto(base + '/cart')
  const checkout = page.getByRole('button', { name: 'Įforminti užsakymą', exact: true })
  await checkout.waitFor()
  assert.equal(await checkout.isDisabled(), true)
  state.offersError = true
  release()
  await page.getByRole('alert').filter({ hasText: 'Nepavyko įkelti krepšelio pasiūlymų' }).waitFor()
  assert.equal(await checkout.isDisabled(), true)
  state.offersError = false
  state.offersHold = null
  state.unavailable = true
  await page.getByRole('button', { name: 'Bandyti dar kartą', exact: true }).click()
  await page.getByText('Nebepasiekiami pasiūlymai neįtraukti į bendrą sumą.', { exact: false }).waitFor()
  assert.equal(await checkout.isDisabled(), true)
  assert.ok(await fits(page))
  state.unavailable = false
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
  await page.getByLabel('Krepšelio suma mobiliajame įrenginyje', { exact: true }).filter({ hasText: '€39.96' }).waitFor()
  assert.equal(await checkout.isEnabled(), true)
})

test('mobile order loading and errors preserve retry and never expose a payment action early', async t => {
  const { page, state, calls } = await setup(t, 390)
  let release
  state.orderHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  await page.goto(base + '/orders/1')
  await page.getByRole('status').filter({ hasText: 'Įkeliamas užsakymas…' }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Mokėti bandomąja kortele', exact: true }).count(), 0)
  state.orderError = true
  release()
  await page.getByRole('alert').filter({ hasText: 'Nepavyko įkelti užsakymo.' }).waitFor()
  assert.ok(await fits(page))
  state.orderError = false
  state.orderHold = null
  await page.getByRole('button', { name: 'Atnaujinti užsakymą', exact: true }).click()
  await page.getByRole('button', { name: 'Mokėti bandomąja kortele', exact: true }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Užsakymo informacija', exact: true }).getAttribute('aria-expanded'), 'false')
  assert.equal(calls.some(c => c.method !== 'GET'), false)
})
