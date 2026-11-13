import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'
const modulePath = process.env.PLAYWRIGHT_MODULE_PATH
const { chromium } = await import(modulePath ? pathToFileURL(modulePath).href : 'playwright')
const base = (process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173').replace(/\/$/, '')
const key = 'eneba:cart'
const test = (name, run) => browserTest(name, { timeout: 20000 }, run)
let browser
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })
const pause = (ms = 160) => new Promise((resolve) => setTimeout(resolve, ms))
const items = (count) => Array.from({ length: count }, (_, i) => ({ offerId: i + 1, quantity: 1 }))
const game = (id, stock = 2) => ({ id, name: `Game ${id}`, img: null, location: ['GLOBAL'], min_price: '9.99', offers_count: 1, offers: [{ id, seller: { id, name: `Seller ${id}` }, price: '9.99', quantity: stock }] })
const offer = (id, stock = 5) => ({ ...game(id, stock).offers[0], game: { id, name: `Game ${id}`, img: null }, currency: 'EUR', available: stock > 0 })
const json = (route, status, data) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })
async function setup(t, saved = items(1)) {
  const context = await browser.newContext()
  const errors = [], requests = []
  const api = { details: (route, id) => json(route, 200, { data: game(id) }), offers: (route, ids) => json(route, 200, { data: ids.map((id) => offer(id)) }) }
  await context.route(/^https?:\/\/[^/]+\/api\//, async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname === '/api/offers') {
      const ids = (url.searchParams.get('ids') ?? '').split(',').filter(Boolean).map(Number)
      requests.push(ids); await api.offers(route, ids)
    } else if (/^\/api\/games\/\d+$/.test(url.pathname)) await api.details(route, Number(url.pathname.split('/').at(-1)))
    else await json(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: { first: '', last: '', prev: null, next: null } })
  })
  context.on('page', (page) => page.on('pageerror', (error) => errors.push(error.message)))
  t.after(async () => { await context.close(); assert.deepEqual(errors, []) })
  const page = await context.newPage(); page.setDefaultTimeout(5000)
  await page.goto(base + '/cart')
  await page.getByRole('heading', { name: 'Your cart is empty', exact: true }).waitFor()
  await page.evaluate(({ key, saved }) => localStorage.setItem(key, JSON.stringify(saved)), { key, saved })
  return { context, page, api, requests }
}
const savedCart = (page) => page.evaluate((key) => JSON.parse(localStorage.getItem(key)), key)
const ready = (page) => page.getByLabel('Cart total', { exact: true }).filter({ hasText: '€' }).waitFor()
async function quantity(page, id, expected) {
  await page.waitForFunction(({ id, expected }) => document.querySelector(`[aria-label="Quantity for offer ${id}"]`)?.textContent === String(expected), { id, expected })
}
test('F02 stale details tab cannot overwrite a newer cart quantity', async (t) => {
  const { context, page } = await setup(t)
  await page.goto(base + '/games/1'); await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor()
  const cart = await context.newPage(); await cart.goto(base + '/cart'); await ready(cart)
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(cart, 1, 2)
  await pause()
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await pause()
  assert.equal((await savedCart(cart))[0].quantity, 3)
  await quantity(cart, 1, 3)
})
test('F03 old 101 position cart loads only first 20, without whole-cart failure', async (t) => {
  const { page, requests } = await setup(t, items(101))
  await page.reload(); await ready(page)
  assert.equal(await page.locator('[data-offer-id]').count(), 20)
  assert.deepEqual(await savedCart(page), items(20))
  assert.ok(requests.every((ids) => ids.length <= 20))
})

function heldResponse() {
  let start, release
  const started = new Promise((resolve) => { start = resolve })
  const result = new Promise((resolve) => { release = resolve })
  return {
    started, release,
    handle: async (route, data) => {
      start()
      await result
      try { await json(route, 200, data) } catch (error) {
        if (!/closed|cancel|abort|intercept|invalid/i.test(String(error))) throw error
      }
    },
  }
}
async function badge(page, count) {
  await page.getByRole('link', { name: `Shopping cart, ${count} ${count === 1 ? 'item' : 'items'}`, exact: true }).waitFor()
}

test('quantity, addition and removal synchronize and survive reload in both tabs', async (t) => {
  const { context, page } = await setup(t)
  await page.reload(); await ready(page)
  const second = await context.newPage(); await second.goto(base + '/cart'); await ready(second)
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click()
  await quantity(second, 1, 2)
  await second.goto(base + '/games/2'); await second.getByRole('heading', { name: 'Game 2', exact: true }).waitFor()
  await second.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await page.locator('[data-offer-id="2"]').waitFor(); await ready(page)
  await page.locator('[data-offer-id="1"]').getByRole('button', { name: 'Remove item from cart', exact: true }).click(); await badge(second, 1)
  assert.deepEqual(await savedCart(page), [{ offerId: 2, quantity: 1 }])
  await page.reload(); await ready(page); await second.reload(); await badge(second, 1)
  assert.deepEqual(await savedCart(second), [{ offerId: 2, quantity: 1 }])
})

test('20 unique offers reject new offer with message; existing offer and freed slot still work', async (t) => {
  const { context, page } = await setup(t, items(20))
  await page.goto(base + '/games/21'); await page.getByRole('heading', { name: 'Game 21', exact: true }).waitFor()
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await page.getByRole('status').filter({ hasText: 'Your cart can contain up to 20 offers.' }).waitFor()
  assert.deepEqual(await savedCart(page), items(20))
  const cart = await context.newPage(); await cart.goto(base + '/cart'); await ready(cart)
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(cart, 1, 2)
  assert.equal((await savedCart(cart)).length, 20)
  await cart.locator('[data-offer-id="20"]').getByRole('button', { name: 'Remove item from cart', exact: true }).click(); await badge(page, 20)
  // Badge is quantity sum: 19 positions, including one quantity=2.
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await cart.locator('[data-offer-id="21"]').waitFor(); assert.equal((await savedCart(cart)).length, 20)
  assert.ok(!(await savedCart(cart)).some((item) => item.offerId === 20))
})

test('old details response cannot clamp newer edits made after its request', async (t) => {
  const { context, page, api } = await setup(t)
  const old = heldResponse(); t.after(() => old.release())
  api.details = (route, id) => old.handle(route, { data: game(id, 2) })
  await page.goto(base + '/games/1'); await old.started
  const cart = await context.newPage(); await cart.goto(base + '/cart'); await ready(cart)
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(cart, 1, 2)
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(cart, 1, 3)
  old.release(); await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor(); await pause()
  assert.equal((await savedCart(cart))[0].quantity, 3); await badge(page, 3)
})

test('old cart response cannot overwrite newer external quantity or metadata', async (t) => {
  const { context, page, api } = await setup(t)
  const old = heldResponse(); t.after(() => old.release())
  api.offers = (route, ids) => {
    api.offers = (r, next) => json(r, 200, { data: next.map((id) => offer(id, 5)) })
    return old.handle(route, { data: ids.map((id) => offer(id, 2)) })
  }
  api.details = (route, id) => json(route, 200, { data: game(id, 5) })
  await page.reload(); await old.started
  const second = await context.newPage(); await second.goto(base + '/games/1'); await second.getByRole('heading', { name: 'Game 1', exact: true }).waitFor()
  await second.getByRole('button', { name: 'Add to cart', exact: true }).click(); await badge(second, 2)
  await second.getByRole('button', { name: 'Add to cart', exact: true }).click(); await badge(second, 3)
  await quantity(page, 1, 3); old.release(); await pause()
  assert.equal((await savedCart(page))[0].quantity, 3); await quantity(page, 1, 3)
  await page.getByText('Available: 5', { exact: true }).waitFor()
})

test('different additions read latest storage even before receiving queued storage events', async (t) => {
  const { context, page } = await setup(t)
  await page.goto(base + '/games/2'); await page.getByRole('heading', { name: 'Game 2', exact: true }).waitFor()
  const second = await context.newPage(); await second.goto(base + '/games/3'); await second.getByRole('heading', { name: 'Game 3', exact: true }).waitFor()
  await second.evaluate(() => {
    window.pauseCartEvents = true
    addEventListener('storage', (event) => { if (window.pauseCartEvents && event.key === 'eneba:cart') event.stopImmediatePropagation() }, true)
  })
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click(); await badge(page, 2)
  await second.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await badge(page, 3); await badge(second, 3)
  await second.evaluate(() => { window.pauseCartEvents = false })
  assert.deepEqual((await savedCart(page)).map((item) => item.offerId), [1, 2, 3])
})

test('two tabs changing one quantity converge to the last stored value without echo loops', async (t) => {
  const { context, page } = await setup(t, [{ offerId: 1, quantity: 2 }])
  await page.reload(); await ready(page)
  const second = await context.newPage(); await second.goto(base + '/cart'); await ready(second)
  await Promise.all([page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(), second.getByRole('button', { name: 'Decrease quantity for offer 1', exact: true }).click()])
  await pause(); const value = (await savedCart(page))[0].quantity
  assert.ok([1, 2, 3].includes(value))
  await quantity(page, 1, value); await quantity(second, 1, value)
  const before = JSON.stringify(await savedCart(page)); await pause(300)
  assert.equal(JSON.stringify(await savedCart(second)), before)
  await page.reload(); await second.reload(); await quantity(page, 1, value); await quantity(second, 1, value)
})

test('simultaneous additions at 19 positions never save 21; losing addition is rejected on retry', async (t) => {
  const { context, page } = await setup(t, items(19))
  await page.goto(base + '/games/20'); await page.getByRole('heading', { name: 'Game 20', exact: true }).waitFor()
  const second = await context.newPage(); await second.goto(base + '/games/21'); await second.getByRole('heading', { name: 'Game 21', exact: true }).waitFor()
  await Promise.all([page.getByRole('button', { name: 'Add to cart', exact: true }).click(), second.getByRole('button', { name: 'Add to cart', exact: true }).click()])
  await pause(); const actual = await savedCart(page)
  assert.equal(actual.length, 20); assert.deepEqual(actual.slice(0, 19), items(19))
  await badge(page, 20); await badge(second, 20)
  const rejected = actual.some((item) => item.offerId === 20) ? second : page
  await rejected.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await rejected.getByRole('status').filter({ hasText: 'Your cart can contain up to 20 offers.' }).waitFor()
  assert.equal((await savedCart(rejected)).length, 20)
})

test('storage events do not echo canonical snapshots back; external oversized snapshots normalize once', async (t) => {
  const { context, page } = await setup(t)
  await page.reload(); await ready(page)
  const second = await context.newPage(); await second.goto(base + '/cart'); await ready(second)
  for (const tab of [page, second]) await tab.evaluate(() => {
    window.cartWrites = 0
    const set = Storage.prototype.setItem
    Storage.prototype.setItem = function (key, value) { if (key === 'eneba:cart') window.cartWrites++; return set.call(this, key, value) }
  })
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(second, 1, 2); await pause()
  assert.equal(await page.evaluate(() => window.cartWrites), 1)
  assert.equal(await second.evaluate(() => window.cartWrites), 0)
  await page.evaluate((key) => dispatchEvent(new StorageEvent('storage', { key, storageArea: localStorage, newValue: JSON.stringify([{ offerId: 1, quantity: 1 }]) })), key)
  await pause(); await quantity(page, 1, 2)
  assert.equal((await savedCart(page))[0].quantity, 2)
  assert.equal(await page.evaluate(() => window.cartWrites), 1)
  await page.evaluate(({ key, value }) => localStorage.setItem(key, JSON.stringify(value)), { key, value: items(101) })
  await page.waitForFunction((key) => JSON.parse(localStorage.getItem(key)).length === 20, key)
  await badge(page, 20); await badge(second, 20)
  const writes = await page.evaluate(() => window.cartWrites) + await second.evaluate(() => window.cartWrites)
  await pause(300)
  assert.equal(await page.evaluate(() => window.cartWrites) + await second.evaluate(() => window.cartWrites), writes)
})

test('legacy normalization survives API errors and retains unavailable offers', async (t) => {
  const { page, api, requests } = await setup(t, items(21))
  api.offers = (route) => json(route, 503, { message: 'Fixture failure' })
  await page.reload(); await page.getByRole('alert').filter({ hasText: 'Failed to load cart offers.' }).waitFor()
  assert.deepEqual(await savedCart(page), items(20))
  assert.ok(requests.every((ids) => ids.length <= 20))
  api.offers = (route, ids) => json(route, 200, { data: ids.filter((id) => id !== 1).map((id) => offer(id)) })
  await page.getByRole('button', { name: 'Try again', exact: true }).click(); await ready(page)
  await page.getByText('This offer is no longer available. Remove it to continue.', { exact: true }).waitFor()
  assert.deepEqual(await savedCart(page), items(20))
})

test('disabled storage keeps subsequent edits in memory and displays the existing warning', async (t) => {
  const { page, api } = await setup(t, [])
  api.details = (route, id) => json(route, 200, { data: game(id, 5) })
  await page.goto(base + '/games/1'); await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor()
  await page.evaluate(() => {
    const set = Storage.prototype.setItem
    Storage.prototype.setItem = function (key, value) { if (key === 'eneba:cart') throw new DOMException('Blocked fixture storage', 'QuotaExceededError'); return set.call(this, key, value) }
  })
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click(); await badge(page, 1)
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click(); await badge(page, 2)
  await page.getByRole('link', { name: 'Shopping cart, 2 items', exact: true }).click(); await ready(page)
  await page.getByRole('alert').filter({ hasText: 'Browser storage is unavailable.' }).waitFor(); await quantity(page, 1, 2)
  await page.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await quantity(page, 1, 3)
})

test('a full cart permits repeated Add to cart for an existing offer; badge counts units', async (t) => {
  const { page, api } = await setup(t, items(20))
  api.details = (route, id) => json(route, 200, { data: game(id, 5) })
  await page.goto(base + '/games/1'); await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor()
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
  await badge(page, 21)
  await page.getByRole('status').filter({ hasText: 'Added to cart.' }).waitFor()
  const actual = await savedCart(page)
  assert.equal(actual.length, 20); assert.equal(actual[0].quantity, 2)
})

test('an old API response cannot clamp after quantity changes and then returns to its original value', async (t) => {
  const { context, page, api } = await setup(t, [{ offerId: 1, quantity: 3 }])
  const old = heldResponse(); t.after(() => old.release())
  api.details = (route, id) => old.handle(route, { data: game(id, 2) })
  await page.goto(base + '/games/1'); await old.started
  const cart = await context.newPage(); await cart.goto(base + '/cart'); await ready(cart)
  await cart.getByRole('button', { name: 'Increase quantity for offer 1', exact: true }).click(); await badge(page, 4)
  await cart.getByRole('button', { name: 'Decrease quantity for offer 1', exact: true }).click(); await badge(page, 3)
  old.release(); await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor(); await pause()
  assert.equal((await savedCart(cart))[0].quantity, 3)
})
