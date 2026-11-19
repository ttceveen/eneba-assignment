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
  const context = await browser.newContext({ viewport: { width: options.width ?? 1280, height: options.height ?? 844 } })
  const state = { stock: 5, catalogError: false, gameError: false, catalogHold: null, gameHold: null }
  const calls = [], errors = []
  const game = id => ({ id, name: id === 1 && state.firstGameName ? state.firstGameName : `Game ${id}`, img: '/images/cyberpunk.jpg', location: ['GLOBAL'], min_price: '9.99', offers_count: 1, offers: [{ id, price: '9.99', quantity: state.stock, seller: { id: 1, name: 'Fixture seller' } }] })
  const order = { id: 1, status: 'pending_payment', total_amount: '9.99', currency: 'EUR', reserved_until: new Date(Date.now() + 900000).toISOString(), items: [{ id: 1, offer_id: 1, quantity: 1, unit_price: '9.99', game: game(1), seller: game(1).offers[0].seller }], payments: [] }
  await context.addInitScript(({ cart, guest }) => {
    if (sessionStorage.getItem('navigation-fixture')) return
    sessionStorage.setItem('navigation-fixture', '1')
    if (!guest) sessionStorage.setItem('eneba:auth-token', 'fixture-token')
    localStorage.setItem('eneba:cart', JSON.stringify(cart))
  }, { cart: options.cart ?? [], guest: options.guest ?? false })
  await context.route(/^https?:\/\/[^/]+\/api\//, async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname
    calls.push({ path, query: url.search, method: request.method() })
    let data, status = 200
    const user = { id: 1, first_name: 'Player', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
    if (path === '/api/login') return route.fulfill({ json: { data: user, token: 'fixture-token', token_type: 'Bearer' } })
    if (path === '/api/promotions') {
      if (state.promotionHold) await state.promotionHold
      const promotions = state.promotions ?? []
      return route.fulfill({ status: state.promotionError ? 503 : 200, json: { data: promotions } })
    }
    if (path === '/api/me') data = user
    else if (path === '/api/games' || path === '/api/games/search') {
      if (state.catalogHold) await state.catalogHold
      if (state.catalogError) return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' })
      const ids = url.searchParams.getAll('ids[]').map(Number)
      const data = ids.length
        ? ids.filter(id => id !== state.missingGame).map(id => ({ ...game(id), min_price: state.detailPrice ?? '9.99' }))
        : state.emptyCatalog ? [] : Array.from({ length: 12 }, (_, i) => game(i + 1))
      if (url.searchParams.get('sort') === 'price_desc') data.reverse()
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(url.searchParams.has('preview') ? { data: data.slice(0, 2), suggestions: ['Game'] } : { data, meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: ids.length || state.emptyCatalog ? 1 : 3, per_page: 12, total: ids.length || state.emptyCatalog ? data.length : 36 }, links: { first: '', last: '', prev: '', next: '' } }) })
    } else if (/^\/api\/games\/\d+$/.test(path)) {
      data = game(Number(path.split('/').at(-1)))
      if (state.missingGame === data.id) status = 404
      if (state.detailPrice) data.min_price = state.detailPrice
      if (state.multipleDetailOffers) data.offers.push({ ...data.offers[0], id: data.id + 100 })
      if (state.detailOffers) {
        data.offers = state.detailOffers
        data.offers_count = state.detailOffers.length
        data.min_price = state.detailOffers[0]?.price ?? null
      }
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
const cartReady = page => page.getByLabel('Krepšelio suma', { exact: true }).filter({ hasText: '€' }).waitFor()
const notices = page => page.getByRole('status').filter({ hasText: 'Pasikeitė turimas kiekis.' })
const quantity = page => page.getByLabel('Pasiūlymo kiekis 1', { exact: true })
const matching = (calls, path) => calls.filter(call => call.path === path)

for (const query of ['?sort=alphabetical&page=2', '?q=Game&page=2', '?q=Game&sort=alphabetical&page=3']) test(`game return preserves catalog ${query}`, async t => {
  const { page } = await setup(t)
  await page.goto(base + '/' + query)
  await card(page, 7).waitFor()
  await page.evaluate(() => document.fonts.ready)
  await card(page, 7).scrollIntoViewIfNeeded()
  const before = await page.locator('[data-game-id="7"]').evaluate(e => e.getBoundingClientRect().top)
  await card(page, 7).click()
  await page.waitForURL('**/games/7')
  await page.getByRole('heading', { name: 'Game 7', exact: true, level: 1 }).waitFor()
  assert.equal(await page.getByRole('link', { name: '← Žaidimai', exact: true }).getAttribute('href'), '/' + query)
  await page.getByRole('link', { name: '← Žaidimai', exact: true }).click()
  await page.waitForURL(base + '/' + query)
  await card(page, 7).waitFor()
  await page.waitForFunction(expected => {
    const card = document.querySelector('[data-game-id="7"]')
    return card && Math.abs(card.getBoundingClientRect().top - expected) < 4
  }, before)
})

for (const searching of [false, true]) test(`changing catalog sort retains filters and query (search=${searching})`, async t => {
  const { page, calls } = await setup(t)
  const filters = { platform: 'Steam', region: 'Global', min_price: '10', max_price: '50' }
  const query = searching ? { q: 'Game' } : {}
  const path = searching ? '/api/games/search' : '/api/games'
  await page.goto(base + '/?' + new URLSearchParams({ ...filters, ...query, page: '2' }))
  await card(page).waitFor()

  const sort = page.getByRole('combobox', { name: 'Rikiavimas' })
  assert.deepEqual(await sort.locator('option').allTextContents(), [
    ...(searching ? ['Pagal atitikimą'] : []),
    'Kaina: nuo mažiausios', 'Kaina: nuo didžiausios', 'Data: nuo naujausių', 'Data: nuo seniausių',
    'Nuo populiariausių', 'Abėcėlė: A–Z', 'Abėcėlė: Z–A',
  ])
  await sort.selectOption({ label: 'Kaina: nuo didžiausios' })
  await page.locator('[data-game-id]').first().filter({ has: card(page, 12) }).waitFor()
  assert.equal(await page.locator('[data-game-id]').first().getAttribute('data-game-id'), '12')
  assert.equal(await sort.inputValue(), 'price_desc')
  assert.equal(await sort.locator('option:checked').textContent(), 'Kaina: nuo didžiausios')
  assert.deepEqual(Object.fromEntries(new URLSearchParams(matching(calls, path).at(-1).query)), {
    ...filters, ...query, sort: 'price_desc', page: '1',
  })
  assert.equal(new URL(page.url()).searchParams.has('page'), false)

  const cleared = page.waitForResponse(response => {
    const url = new URL(response.url())
    return url.pathname === path && !url.searchParams.has('region') && response.ok()
  })
  await page.getByRole('group', { name: 'Greitieji filtrai' }).getByRole('button', { name: 'Išvalyti viską' }).click()
  await cleared
  await card(page).waitFor()
  assert.deepEqual(Object.fromEntries(new URLSearchParams(matching(calls, path).at(-1).query)), {
    ...query, ...(searching ? {} : { platform: 'Steam' }), sort: 'price_desc', page: '1',
  })
  assert.equal(await sort.inputValue(), 'price_desc')
})

test('mobile category filters restore URL state and clear within the platform', async t => {
  const { page } = await setup(t, { width: 390 })
  await page.goto(base + '/?platform=Steam')
  const sidebar = page.getByRole('complementary', { name: 'Katalogo filtrai' })
  const toggle = sidebar.getByRole('button', { name: 'Filtrai', exact: true })
  const europe = sidebar.getByRole('radio', { name: 'Europa', exact: true })
  const global = sidebar.getByRole('radio', { name: 'Visame pasaulyje', exact: true })
  assert.equal(await toggle.getAttribute('aria-expanded'), 'false')
  assert.equal(await europe.isVisible(), false)
  await toggle.click()
  await europe.click()
  await page.waitForURL(url => url.searchParams.get('region') === 'Europe')
  await global.click()
  await page.waitForURL(url => url.searchParams.get('region') === 'Global')
  await page.goBack()
  await europe.and(page.locator(':checked')).waitFor()
  await page.goForward()
  await global.and(page.locator(':checked')).waitFor()
  await page.reload()
  await toggle.click()
  await global.and(page.locator(':checked')).waitFor()
  await toggle.click()
  assert.equal(await global.isVisible(), false)
  await sidebar.getByRole('button', { name: 'Išvalyti viską' }).click()
  await page.waitForURL(url => !url.searchParams.has('region'))
  assert.equal(new URL(page.url()).searchParams.get('platform'), 'Steam')
})

test('mobile search keeps filters and sorting through pagination and clear', async t => {
  const { page, calls } = await setup(t, { width: 320 })
  await page.goto(base + '/?q=Game&page=2')
  const sidebar = page.getByRole('complementary', { name: 'Katalogo filtrai' })
  const toggle = sidebar.getByRole('button', { name: 'Filtrai', exact: true })
  await toggle.click()
  await sidebar.getByRole('radio', { name: 'Xbox', exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('platform') === 'Xbox')
  await sidebar.getByRole('radio', { name: 'Xbox', exact: true }).and(page.locator(':checked')).waitFor()
  await sidebar.getByRole('radio', { name: 'Europa', exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('region') === 'Europe')
  await sidebar.getByRole('radio', { name: 'Europa', exact: true }).and(page.locator(':checked')).waitFor()
  await sidebar.getByRole('spinbutton', { name: 'Min. kaina (€)' }).fill('2')
  await page.waitForURL(url => url.searchParams.get('min_price') === '2')
  await sidebar.locator('input[type=number][value="2"]').waitFor()
  await sidebar.getByRole('spinbutton', { name: 'Maks. kaina (€)' }).fill('50')
  await page.waitForURL(url => url.searchParams.get('max_price') === '50')
  await sidebar.locator('input[type=number][value="50"]').waitFor()
  await sidebar.getByRole('checkbox', { name: 'Populiarūs' }).click()
  await page.waitForURL(url => url.searchParams.get('popular_only') === '1')
  await sidebar.getByRole('checkbox', { name: 'Populiarūs' }).and(page.locator(':checked')).waitFor()
  await toggle.click()
  assert.match(await toggle.textContent(), /5/, page.url())
  const sort = page.getByRole('combobox', { name: 'Rikiavimas' })
  await sort.selectOption('price_desc')
  const params = { q: 'Game', platform: 'Xbox', region: 'Europe', min_price: '2', max_price: '50', popular_only: '1', sort: 'price_desc' }
  function matches(query, expected, pageNumber) {
    const actual = new URLSearchParams(query)
    return actual.get('page') === pageNumber && Object.entries(expected).every(([key, value]) => actual.get(key) === value)
  }
  await page.waitForURL(url => matches(url.search, params, null))
  await page.getByRole('button', { name: 'Puslapis 2' }).click()
  await page.waitForURL(url => matches(url.search, params, '2'))
  await page.waitForFunction(() => document.querySelector('button[aria-label="Puslapis 2"]')?.getAttribute('aria-current') === 'page')
  assert.ok(matching(calls, '/api/games/search').some(call => matches(call.query, params, '2')))
  await sidebar.getByRole('button', { name: 'Išvalyti viską' }).click()
  await page.waitForURL(url => url.searchParams.size === 2 && matches(url.search, { q: 'Game', sort: 'price_desc' }, null))
})

for (const width of [320, 390, 768, 1440]) test(`catalog card layout and actions at ${width}px`, async t => {
  const { page, state } = await setup(t, { width, guest: true })
  const name = width === 320 ? 'Game 1 with a long title that wraps beyond two lines in the compact catalog card' : 'Game 1'
  state.firstGameName = name
  await page.goto(base + '/?sort=popular')
  const cards = page.getByRole('region', { name: 'Katalogo rezultatai' }).getByRole('article')
  await cards.first().waitFor()
  const mobile = width <= 580
  const first = cards.first()
  const box = await first.boundingBox()
  const cover = await first.locator('img').boundingBox()
  const title = await first.getByRole('link', { name, exact: true }).boundingBox()
  assert.equal(cover.x + cover.width <= title.x, mobile)
  const next = await cards.nth(1).boundingBox()
  assert.equal(next.y > box.y, mobile)
  if (width === 1440) {
    assert.equal((await cards.nth(3).boundingBox()).y, box.y)
    assert.ok((await cards.nth(4).boundingBox()).y > box.y)
    const add = first.getByRole('button', { name: 'Į krepšelį' })
    assert.equal(await add.isVisible(), false)
    assert.ok(Math.abs(cover.width / cover.height - 5 / 7) < .01)
    await first.locator('img').hover()
    await add.waitFor()
    assert.deepEqual(await first.boundingBox(), box)
    assert.deepEqual(await cards.nth(1).boundingBox(), next)
  }
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width)
  if (mobile || width === 1440) {
    const add = first.getByRole('button', { name: 'Į krepšelį' })
    assert.ok((await add.boundingBox()).height >= (mobile ? 44 : 38))
    await add.click()
    await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 1' }).waitFor()
    await first.getByText('Pridėta į krepšelį.').waitFor()
    await first.getByRole('link', { name: 'Peržiūrėti pasiūlymus' }).click()
    await page.waitForURL('**/games/1')
  }
})

for (const multiple of [false, true]) test(`home quick add verifies the seller offer (multiple=${multiple})`, async t => {
  const { page, state, calls } = await setup(t)
  state.multipleDetailOffers = multiple
  await page.goto(base + '/')
  const tile = page.getByRole('region', { name: 'Naujienos' }).locator('[data-game-id="1"]')
  await tile.hover()
  await tile.getByRole('button', { name: 'Į krepšelį' }).click()
  if (multiple) {
    await page.waitForURL('**/games/1')
    await page.getByRole('region', { name: 'Pardavėjų pasiūlymai' }).waitFor()
    assert.equal(await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 0' }).count(), 1)
  } else {
    await tile.getByRole('status').filter({ hasText: 'Pridėta į krepšelį.' }).waitFor()
    await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 1' }).waitFor()
    assert.equal(page.url(), base + '/')
    assert.equal(matching(calls, '/api/games/1').length, 1)
  }
})

test('home carousel tracks its scroll and opens the positive-sales catalog', async t => {
  const { page } = await setup(t)
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await page.goto(base + '/')
  const shelf = page.getByRole('region', { name: 'Populiariausi žaidimai' })
  const earlier = shelf.getByRole('button', { name: 'Slinkti kairėn' })
  const later = shelf.getByRole('button', { name: 'Slinkti dešinėn' })
  const position = shelf.getByRole('progressbar')
  await later.waitFor()
  assert.equal(await earlier.isDisabled(), true)
  await later.click()
  await shelf.locator('[role="progressbar"]:not([aria-valuenow="0"])').waitFor()
  assert.ok(Number(await position.getAttribute('aria-valuenow')) > 0)
  assert.equal(await earlier.isEnabled(), true)

  await shelf.getByRole('link', { name: 'Rodyti visus' }).click()
  await page.waitForURL('**/?sort=popular&popular_only=1')
  const quick = page.getByRole('group', { name: 'Greitieji filtrai' })
  assert.equal(await quick.getByRole('checkbox', { name: 'Populiarūs' }).isChecked(), true)
  await quick.getByRole('button', { name: 'Išvalyti viską' }).click()
  await page.waitForURL('**/?sort=popular')
  await quick.locator('input[type=checkbox]:not(:checked)').waitFor()
})

test('recent games follow guest navigation, keep 12 unique IDs, and survive sign-in', async t => {
  const { page, calls, state } = await setup(t, { guest: true })
  const key = 'eneba:recently-viewed-games'
  const readHistory = () => page.evaluate(storageKey => JSON.parse(localStorage.getItem(storageKey) ?? '[]'), key)
  const recent = page.getByRole('region', { name: 'Neseniai peržiūrėta' })
  async function visit(id) {
    await page.goto(base + '/games/' + id)
    await page.getByRole('heading', { name: `Game ${id}`, exact: true }).waitFor()
  }
  async function expectCards(expected) {
    const cards = recent.locator('[data-game-id]')
    await cards.nth(expected.length - 1).waitFor()
    assert.deepEqual(await cards.evaluateAll(items => items.map(item => Number(item.dataset.gameId))), expected)
  }

  await page.goto(base + '/')
  await page.getByRole('region', { name: 'Naujienos' }).locator('[data-game-id]').first().waitFor()
  assert.equal(await recent.count(), 0)
  await page.evaluate(storageKey => localStorage.setItem(storageKey, '{broken'), key)
  await visit(1)
  assert.deepEqual(await readHistory(), [1])
  await visit(2)
  assert.deepEqual(await readHistory(), [2, 1])
  for (let id = 3; id <= 13; id++) await visit(id)
  await visit(4)
  assert.deepEqual(await readHistory(), [4, 13, 12, 11, 10, 9, 8, 7, 6, 5, 3, 2])

  const requestCount = calls.length
  state.missingGame = 13
  state.detailPrice = '19.99'
  await page.getByRole('link', { name: '← Žaidimai', exact: true }).click()
  await expectCards([4, 12, 11, 10, 9, 8, 7, 6, 5, 3, 2])
  const historyRequests = calls.slice(requestCount).filter(call => new URLSearchParams(call.query).has('ids[]'))
  assert.equal(historyRequests.length, 1)
  assert.deepEqual(new URLSearchParams(historyRequests[0].query).getAll('ids[]').map(Number), [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13])
  assert.match(await recent.locator('[data-game-id="4"]').innerText(), /19\.99/)
  await page.reload()
  await expectCards([4, 12, 11, 10, 9, 8, 7, 6, 5, 3, 2])

  await page.getByRole('link', { name: 'Prisijungti', exact: true }).click()
  await page.getByLabel('El. paštas', { exact: true }).fill('fixture@example.test')
  await page.getByLabel('Slaptažodis', { exact: true }).fill('FixturePassword123')
  await page.getByRole('button', { name: 'Prisijungti', exact: true }).click()
  await page.getByRole('button', { name: 'Atsijungti', exact: true }).waitFor()
  await expectCards([4, 12, 11, 10, 9, 8, 7, 6, 5, 3, 2])
  assert.equal((await readHistory())[0], 4)
  await recent.getByRole('link', { name: 'Rodyti visus' }).click()
  await page.waitForURL('**/?view=recent')
  const catalog = page.getByRole('region', { name: 'Katalogo rezultatai' })
  await catalog.locator('[data-game-id]').nth(10).waitFor()
  assert.deepEqual(await catalog.locator('[data-game-id]').evaluateAll(cards => cards.map(card => Number(card.dataset.gameId))), [4, 12, 11, 10, 9, 8, 7, 6, 5, 3, 2])
  assert.equal(await page.getByRole('combobox', { name: 'Rikiavimas' }).count(), 0)
  await page.getByRole('group', { name: 'Greitieji filtrai' }).getByRole('combobox', { name: 'Platformos' }).selectOption('Steam')
  await page.waitForURL(url => url.searchParams.get('platform') === 'Steam')
  assert.equal(new URL(page.url()).searchParams.get('view'), 'recent')
})

test('unavailable recent-history storage does not break browsing', async t => {
  const { context, page } = await setup(t, { guest: true })
  await context.addInitScript(() => {
    const original = Storage.prototype.setItem
    Storage.prototype.setItem = function (key, value) {
      if (key === 'eneba:recently-viewed-games') throw new Error('History storage is blocked')
      return original.call(this, key, value)
    }
  })
  await page.goto(base + '/games/1')
  await page.getByRole('heading', { name: 'Game 1', exact: true }).waitFor()
  await page.getByRole('link', { name: '← Žaidimai', exact: true }).click()
  await page.getByRole('region', { name: 'Naujienos' }).locator('[data-game-id]').first().waitFor()
  assert.equal(await page.getByRole('region', { name: 'Neseniai peržiūrėta' }).count(), 0)
})

test('direct game entry ignores an unrelated stored catalog', async t => {
  const { page } = await setup(t)
  await page.goto(base + '/?sort=alphabetical&page=2')
  await card(page).waitFor()
  await page.goto(base + '/games/1')
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(await page.getByRole('link', { name: '← Žaidimai', exact: true }).getAttribute('href'), '/')
})

test('clear search still returns to the original non-search catalog after a game visit', async t => {
  const { page } = await setup(t)
  const catalog = base + '/?sort=alphabetical&page=2'
  await page.goto(catalog)
  await card(page).waitFor()
  await page.getByRole('searchbox', { name: 'Ieškoti žaidimų', exact: true }).click()
  await page.locator('#search-query').fill('Game')
  await page.getByRole('button', { name: 'Paieška', exact: true }).click()
  await page.waitForURL('**/?q=Game')
  await card(page).click()
  await page.waitForURL('**/games/1')
  await page.getByRole('link', { name: '← Žaidimai', exact: true }).click()
  await page.waitForURL('**/?q=Game')
  await page.getByRole('button', { name: 'Išvalyti paiešką', exact: true }).click()
  await page.waitForURL(catalog)
})

for (const query of ['?sort=alphabetical&page=2', '?q=Game&page=2']) test(`catalog Retry sends one GET and retains ${query}`, async t => {
  const { page, calls, state } = await setup(t, { width: 390 })
  state.catalogError = true
  await page.goto(base + '/' + query)
  await page.getByRole('alert').filter({ hasText: 'Nepavyko įkelti žaidimų.' }).waitFor()
  const path = query.includes('q=') ? '/api/games/search' : '/api/games'
  assert.equal(matching(calls, path).length, 1)
  const documentRequests = []
  page.on('request', request => { if (request.isNavigationRequest()) documentRequests.push(request.url()) })
  let release
  state.catalogHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  state.catalogError = false
  await page.getByRole('button', { name: 'Bandyti dar kartą', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.getByRole('status').filter({ hasText: 'Įkeliami žaidimai…' }).waitFor()
  assert.equal(await page.getByRole('button', { name: 'Bandyti dar kartą', exact: true }).count(), 0)
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
  await page.getByRole('alert').filter({ hasText: 'Nepavyko įkelti žaidimo.' }).waitFor()
  assert.equal(matching(calls, '/api/games/1').length, 1)
  const catalogCount = matching(calls, '/api/games/search').length
  let release
  state.gameHold = new Promise(resolve => { release = resolve })
  t.after(() => release())
  state.gameError = false
  await page.getByRole('button', { name: 'Bandyti dar kartą', exact: true }).evaluate(button => { button.click(); button.click() })
  await page.getByRole('status').filter({ hasText: 'Įkeliamas žaidimas…' }).waitFor()
  release()
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(matching(calls, '/api/games/1').length, 2)
  assert.equal(matching(calls, '/api/games/search').length, catalogCount)
  assert.equal(await page.getByRole('link', { name: '← Žaidimai', exact: true }).getAttribute('href'), '/?q=Game&page=2')
  await page.getByRole('heading', { name: 'Pardavėjų pasiūlymai', exact: true }).waitFor()
  await page.getByText('Turimas kiekis: 5', { exact: true }).waitFor()
})

test('automatic stock reduction is announced once; manual quantity edits never announce it', async t => {
  const { page, state } = await setup(t, { width: 360, cart: [{ offerId: 1, quantity: 3 }] })
  await page.goto(base + '/cart')
  await cartReady(page)
  assert.equal(await notices(page).count(), 0)
  state.stock = 2
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
  await quantity(page).filter({ hasText: '2' }).waitFor()
  await notices(page).filter({ hasText: 'Kiekis pakeistas iš 3 į 2.' }).waitFor()
  assert.equal(await notices(page).count(), 1)
  await page.getByLabel('Krepšelio suma mobiliajame įrenginyje', { exact: true }).filter({ hasText: '€19.98' }).waitFor()
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 1)
  await page.getByRole('button', { name: 'Sumažinti pasiūlymo kiekį 1', exact: true }).click()
  await quantity(page).filter({ hasText: '1' }).waitFor()
  assert.equal(await notices(page).count(), 0)
  await page.getByRole('button', { name: 'Padidinti pasiūlymo kiekį 1', exact: true }).click()
  await quantity(page).filter({ hasText: '2' }).waitFor()
  assert.equal(await notices(page).count(), 0)
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 0)
})

test('stock reduction while loading a game is announced there and retained in the cart', async t => {
  const { page, state } = await setup(t, { cart: [{ offerId: 1, quantity: 3 }] })
  state.stock = 2
  await page.goto(base + '/games/1')
  await notices(page).filter({ hasText: 'Kiekis pakeistas iš 3 į 2.' }).waitFor()
  await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 2', exact: true }).click()
  await cartReady(page)
  assert.equal(await notices(page).count(), 1)
  await page.getByRole('button', { name: 'Atnaujinti kainas', exact: true }).click()
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
  await page.getByRole('status').filter({ hasText: 'Įkeliamas žaidimas…' }).waitFor()
  await other.evaluate(() => localStorage.setItem('eneba:cart', JSON.stringify([{ offerId: 1, quantity: 4 }])))
  await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 4', exact: true }).waitFor()
  release()
  await page.getByRole('heading', { name: 'Game 1', exact: true, level: 1 }).waitFor()
  assert.equal(await notices(page).count(), 0)
  assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))), [{ offerId: 1, quantity: 4 }])
})

test('pending payment explains locked method without unlocking it or confirming automatically', async t => {
  const { page, order, calls } = await setup(t, { width: 390 })
  order.payments = [{ id: 1, order_id: 1, provider: 'paypal', external_id: 'ORDER123', redirect_url: 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', idempotency_key: 'fixture-key', status: 'pending', amount: '9.99', currency: 'EUR', error_code: null, error_message: null }]
  await page.goto(base + '/orders/1')
  await page.getByText('Kol vyksta šis mokėjimo bandymas, mokėjimo būdo pakeisti negalima.', { exact: true }).waitFor()
  assert.equal(await page.getByLabel('Fake Card', { exact: true }).isDisabled(), true)
  assert.equal(await page.getByLabel('PayPal Sandbox', { exact: true }).isDisabled(), true)
  assert.equal(await page.getByLabel('PayPal Sandbox', { exact: true }).isChecked(), true)
  assert.equal(await page.getByRole('button', { name: 'Patvirtinti „PayPal“ mokėjimą', exact: true }).count(), 1)
  assert.equal(await page.getByRole('button', { name: 'Tęsti „PayPal“', exact: true }).count(), 1)
  assert.equal(calls.some(call => call.method !== 'GET'), false)
  assert.equal(await page.getByRole('button', { name: 'Užsakymo informacija', exact: true }).getAttribute('aria-expanded'), 'false')
})


for (const width of [320, 375, 390, 430, 600, 768, 820, 1024, 1280, 1440]) test(`header layout and search controls at ${width}px`, async t => {
  const { page } = await setup(t, { width, height: width === 1440 ? 900 : 844, guest: true, cart: [{ offerId: 1, quantity: 2 }] })
  await page.goto(base + '/')
  const header = page.getByRole('banner')
  const mobile = width <= 600
  const trigger = header.getByRole(mobile ? 'button' : 'searchbox', { name: 'Ieškoti žaidimų', exact: true })
  const account = header.getByRole('link', { name: 'Prisijungti', exact: true })
  const register = header.getByRole('link', { name: 'Registruotis', exact: true })
  const cart = header.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 2' })
  await register.waitFor()
  const tablet = !mobile && width <= 1100
  const height = mobile ? 56 : tablet ? Math.max(64, Math.min(width * .085, 88)) : 94
  const categoryBar = header.getByRole('navigation', { name: 'Katalogo navigacija' })
  const categoryHeight = width >= 900 ? 50 : 0
  assert.equal(await categoryBar.isVisible(), width >= 900)
  assert.ok(Math.abs((await header.boundingBox()).height - height - categoryHeight) < 1)
  assert.equal(await header.evaluate(element => getComputedStyle(element).backgroundColor), width <= 1100 ? 'rgb(70, 24, 172)' : 'rgb(77, 41, 144)')
  assert.equal(await cart.textContent(), '2')
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width)
  const logo = header.getByRole('link', { name: 'Eneba pradžia' })
  const ordered = mobile ? [logo, trigger, account, register, cart] : [logo, trigger, cart, account, register]
  const boxes = await Promise.all(ordered.map(locator => locator.boundingBox()))
  assert.ok(boxes.every((box, index) => box.x >= 0 && box.x + box.width <= width && (!index || boxes[index - 1].x + boxes[index - 1].width <= box.x + 1)))
  if (mobile) assert.ok(boxes.every(box => box.width >= 44 && box.height >= 44))
  await trigger.click()
  const dialog = page.getByRole('dialog', { name: 'Ieškoti žaidimų' })
  const query = dialog.getByRole('searchbox')
  await query.waitFor()
  assert.ok(await query.evaluate(element => document.activeElement === element))
  assert.equal(await dialog.evaluate(element => element.matches(':modal')), !mobile)
  await query.press('Escape')
  await dialog.waitFor({ state: 'hidden' })
  assert.ok(await trigger.evaluate(element => document.activeElement === element))
})

test('desktop categories dismiss and link to the active catalog platform', async t => {
  const { page } = await setup(t, { width: 1440, guest: true })
  await page.goto(base + '/cart')
  const header = page.getByRole('banner')
  const trigger = header.getByRole('button', { name: 'Kategorijos', exact: true })
  const menu = page.locator('[id=' + JSON.stringify(await trigger.getAttribute('aria-controls')) + ']')
  await trigger.focus()
  await page.keyboard.press('Space')
  assert.equal(await trigger.getAttribute('aria-expanded'), 'true')
  const steam = menu.getByRole('link', { name: 'Steam', exact: true })
  await page.keyboard.press('Tab')
  assert.ok(await steam.evaluate(link => link === document.activeElement && link.matches(':focus-visible')))
  await page.keyboard.press('Escape')
  await menu.waitFor({ state: 'hidden' })
  assert.ok(await trigger.evaluate(button => button === document.activeElement))
  await trigger.click()
  await trigger.click()
  await menu.waitFor({ state: 'hidden' })
  await trigger.click()
  await header.getByText('EUR', { exact: true }).click()
  await menu.waitFor({ state: 'hidden' })
  await trigger.click()
  await steam.click()
  await page.waitForURL('**/?platform=Steam')
  await menu.waitFor({ state: 'hidden' })
  const nav = header.getByRole('navigation', { name: 'Katalogo navigacija' })
  await nav.getByRole('link', { name: 'Steam', exact: true }).and(page.locator('[aria-current="page"]')).waitFor()
  await nav.getByRole('link', { name: 'Žaidimai', exact: true }).click()
  await page.waitForURL(base + '/')
})

test('compact Header opens categories and closes after choosing Nintendo', async t => {
  const { page } = await setup(t, { width: 320, guest: true })
  await page.goto(base + '/')
  const header = page.getByRole('banner')
  const trigger = header.getByRole('button', { name: 'Kategorijos', exact: true })
  const nav = header.getByRole('navigation', { name: 'Katalogo navigacija' })
  assert.equal(await nav.isVisible(), false)
  await trigger.click()
  const steam = nav.getByRole('link', { name: 'Steam', exact: true })
  await steam.waitFor()
  await page.waitForFunction(() => document.activeElement?.textContent === 'Steam')
  await steam.press('Escape')
  assert.ok(await trigger.evaluate(button => button === document.activeElement))
  await trigger.click()
  await nav.getByRole('link', { name: 'Nintendo', exact: true }).click()
  await page.waitForURL('**/?platform=Nintendo')
  await nav.waitFor({ state: 'hidden' })
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 320)
})

test('mobile search permits Tab out, restores focus and submits suggestions using the existing route', async t => {
  const { page, calls } = await setup(t, { width: 390, guest: true, cart: [{ offerId: 1, quantity: 2 }] })
  await page.goto(base + '/')
  const header = page.getByRole('banner')
  const trigger = header.getByRole('button', { name: 'Ieškoti žaidimų', exact: true })
  const account = header.getByRole('link', { name: 'Prisijungti', exact: true })
  await header.getByRole('link', { name: 'Registruotis', exact: true }).waitFor()
  await trigger.press('Enter')
  assert.equal(await trigger.getAttribute('aria-expanded'), 'true')
  await page.keyboard.press('Tab')
  assert.ok(await account.evaluate(element => document.activeElement === element))
  await account.press('Escape')
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false')
  assert.ok(await trigger.evaluate(element => document.activeElement === element))
  await trigger.click()
  await header.getByRole('button', { name: 'Uždaryti paiešką' }).click()
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false')
  assert.ok(await trigger.evaluate(element => document.activeElement === element))
  await trigger.click()
  const query = page.locator('#search-query')
  await query.fill('Game')
  await page.getByRole('region', { name: 'Paieškos pasiūlymai' }).getByRole('button', { name: 'Game', exact: true }).waitFor()
  await query.press('Enter')
  await page.waitForURL('**/?q=Game')
  await page.getByRole('heading', { name: 'Paieškos rezultatai pagal “Game”' }).waitFor()
  assert.ok(matching(calls, '/api/games/search').some(call => new URLSearchParams(call.query).get('q') === 'Game'))
  await account.click()
  await page.waitForURL('**/login')
  await page.getByRole('link', { name: 'Sukurti paskyrą', exact: true }).click()
  await page.waitForURL('**/register')
  await page.getByRole('link', { name: 'Eneba pradžia', exact: true }).click()
  await header.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 2' }).click()
  await page.waitForURL('**/cart')
  await cartReady(page)
})

test('authenticated mobile header exposes account and sign out without crowding the cart', async t => {
  const { page } = await setup(t, { width: 320 })
  await page.goto(base + '/')
  const header = page.getByRole('banner')
  const account = header.getByRole('link', { name: 'Užsakymų istorija' })
  await account.waitFor()
  const signOut = await header.getByRole('button', { name: 'Atsijungti' }).boundingBox()
  const cart = await header.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 0' }).boundingBox()
  assert.ok(signOut.x + signOut.width <= cart.x)
  assert.equal((await header.boundingBox()).height, 56)
  await account.click()
  await page.waitForURL('**/orders')
})


const bannerFixture = { id: 1, title: 'Promotion from API', image_url: '/images/banners/gta-vi-desktop.jpg', link_url: '#' }

test('promotion uses API artwork and title without navigating for a placeholder link', async t => {
  const { page, state } = await setup(t, { width: 390, guest: true })
  state.promotions = [bannerFixture, { ...bannerFixture, id: 2, title: 'Second promotion' }]
  let release
  state.promotionHold = new Promise(resolve => { release = resolve })
  t.after(release)
  await page.goto(base + '/')
  const artwork = page.getByRole('img', { name: bannerFixture.title })
  const shelf = page.getByRole('heading', { name: 'Naujienos' })
  await shelf.waitFor()
  const before = (await shelf.boundingBox()).y
  assert.equal(await artwork.count(), 0)
  release()
  await artwork.evaluate(image => image.decode())
  assert.equal((await shelf.boundingBox()).y, before)
  const box = await artwork.boundingBox()
  assert.equal(box.width, 390)
  assert.ok(Math.abs(box.height - 189) < 1)
  assert.equal(new URL(await artwork.getAttribute('src')).pathname, bannerFixture.image_url)
  assert.equal(await page.getByRole('img', { name: 'Second promotion' }).count(), 0)
  assert.equal(await page.getByRole('link', { name: bannerFixture.title }).count(), 0)
  await artwork.click()
  assert.equal(page.url(), base + '/')
})

test('promotions allow HTTP links and render API titles as text', async t => {
  const { page, state } = await setup(t, { guest: true })
  state.promotions = [{ ...bannerFixture, title: '<b>Promotion</b>', link_url: '/?sort=newest' }]
  await page.goto(base + '/')
  const link = page.getByRole('link', { name: '<b>Promotion</b>' })
  await link.waitFor()
  assert.equal(new URL(await link.getAttribute('href')).search, '?sort=newest')
  await link.click()
  await page.waitForURL('**/?sort=newest')
})

test('promotions handle unsafe URLs, empty data and API failure while shelves load', async t => {
  const { page, state } = await setup(t, { guest: true })
  state.promotions = [{ ...bannerFixture, link_url: 'javascript:window.alert(1)' }]
  await page.goto(base + '/')
  await page.getByRole('img', { name: bannerFixture.title }).waitFor()
  assert.equal(await page.getByRole('link', { name: bannerFixture.title }).count(), 0)
  for (const promotionError of [false, true]) {
    state.promotions = []
    state.promotionError = promotionError
    const response = page.waitForResponse(res => new URL(res.url()).pathname === '/api/promotions')
    await page.reload()
    assert.equal((await response).status(), promotionError ? 503 : 200)
    await page.getByRole('heading', { name: 'Naujienos' }).waitFor()
    assert.equal(await page.getByRole('img', { name: /promotion from API|Grand Theft Auto VI/i }).count(), 0)
  }
})

test('empty mobile search and filtered catalog keep their result messages', async t => {
  const { page, state } = await setup(t, { width: 390 })
  state.emptyCatalog = true
  for (const query of ['?q=Missing', '?sort=popular&region=Europe', '?sort=popular']) {
    await page.goto(base + '/' + query)
    await page.getByText('Rasta žaidimų: 0', { exact: true }).waitFor()
    await page.getByText(query === '?sort=popular' ? 'Nėra pasiekiamų žaidimų.' : 'Žaidimų nerasta.', { exact: true }).waitFor()
    await page.getByRole('combobox', { name: 'Rikiavimas' }).waitFor()
  }
})


for (const [width, height] of [[390, 844], [768, 1024], [1440, 900]]) test(`game details preserve the seller list in the purchase layout at ${width}px`, async t => {
  const { page, state } = await setup(t, { width, height, guest: true })
  const name = 'Game 1: A long title for a special edition with an extended description'
  state.firstGameName = name
  state.detailOffers = [
    { id: 19, seller: { id: 9, name: 'First seller' }, price: '19.95', quantity: 1 },
    { id: 21, seller: { id: 3, name: 'Second seller' }, price: '11.50', quantity: 3 },
    { id: 12, seller: { id: 6, name: 'Third seller' }, price: '25.00', quantity: 2 },
  ]
  await page.goto(base + '/?sort=popular')
  await page.getByRole('link', { name, exact: true }).click()
  await page.waitForURL('**/games/1')
  const title = page.getByRole('heading', { level: 1, name })
  const cover = page.getByRole('img', { name: name + ' viršelis' })
  const offers = page.getByRole('region', { name: 'Pardavėjų pasiūlymai' }).getByRole('listitem')
  await cover.evaluate(image => image.decode())
  assert.equal(await offers.count(), state.detailOffers.length)
  for (const [index, offer] of state.detailOffers.entries()) {
    const row = offers.nth(index)
    assert.ok(await row.getByText(offer.seller.name, { exact: true }).isVisible())
    assert.ok(await row.getByText('€' + offer.price, { exact: true }).isVisible())
    assert.ok(await row.getByText('Turimas kiekis: ' + offer.quantity, { exact: true }).isVisible())
  }
  const [art, heading, purchase] = await Promise.all([cover.boundingBox(), title.boundingBox(), offers.first().boundingBox()])
  const ratio = await cover.evaluate(image => image.naturalWidth / image.naturalHeight)
  assert.ok(Math.abs(art.width / art.height - ratio) < .01)
  assert.ok(heading.x > art.x + art.width)
  assert.ok(Math.abs(art.y - heading.y) < 3)
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), width)
  const primary = offers.first(), secondary = offers.nth(1)
  assert.equal(await primary.evaluate(row => getComputedStyle(row).backgroundColor), 'rgb(88, 37, 204)')
  assert.equal(await secondary.evaluate(row => getComputedStyle(row).backgroundColor), 'rgb(53, 17, 131)')
  if (width === 390) {
    assert.ok(art.width < 90)
    assert.ok(purchase.y >= Math.max(art.y + art.height, heading.y + heading.height))
  } else {
    assert.ok(purchase.x >= heading.x + heading.width)
    if (width === 1440) {
      assert.ok(Math.abs(art.width - 260) <= 2, `Expected cover width near 260px, got ${art.width}px`)
      assert.ok(Math.abs(purchase.width - 360) <= 2, `Expected offer width near 360px, got ${purchase.width}px`)
      const titleSize = await title.evaluate(element => Number.parseFloat(getComputedStyle(element).fontSize))
      assert.ok(Math.abs(titleSize - 34) <= 1, `Expected title size near 34px, got ${titleSize}px`)
    }
  }
  const buy = primary.getByRole('button', { name: 'Į krepšelį' })
  assert.ok((await buy.boundingBox()).y < height - 48)
  await page.getByRole('link', { name: '← Žaidimai' }).focus()
  await page.keyboard.press('Tab')
  assert.ok(await primary.getByRole('radio').evaluate(button => button.matches(':focus-visible')))
  await page.keyboard.press('Tab')
  assert.ok(await buy.evaluate(button => button.matches(':focus-visible') && getComputedStyle(button).outlineStyle === 'solid'))
  await page.keyboard.press('Enter')
  await primary.getByRole('button', { name: 'Pasiektas turimo kiekio limitas' }).waitFor()
  assert.equal(await primary.getByRole('button').isDisabled(), true)
  await secondary.getByRole('button', { name: 'Į krepšelį' }).click()
  await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 2' }).waitFor()
  assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))), [
    { offerId: 19, quantity: 1 },
    { offerId: 21, quantity: 1 },
  ])
})

test('game details keep the empty offer state and omit purchase actions', async t => {
  const { page, state } = await setup(t, { width: 320, guest: true })
  state.detailOffers = []
  await page.goto(base + '/games/1')
  await page.getByRole('heading', { level: 1, name: 'Game 1' }).waitFor()
  const offers = page.getByRole('region', { name: 'Pardavėjų pasiūlymai' })
  assert.ok(await offers.getByText('Pasiūlymų nėra', { exact: true }).isVisible())
  assert.equal(await offers.getByRole('button').count(), 0)
})


test('storefront header and footer remain outside the auth routes', async t => {
  const { page } = await setup(t, { guest: true })
  for (const href of ['/', '/?sort=popular', '/?q=Game', '/games/1', '/cart']) {
    await page.goto(base + href)
    await page.getByRole('banner').getByRole('link', { name: 'Eneba pradžia', exact: true }).waitFor()
    await page.getByRole('contentinfo').getByRole('navigation', { name: 'Poraštės navigacija' }).waitFor()
  }
})


const platformOffers = [
  { id: 22, seller: { id: 1, name: 'PixelStore' }, platform: 'Xbox', price: '59.99', quantity: 3 },
  { id: 23, seller: { id: 2, name: 'GameHub' }, platform: 'Nintendo', price: '61.24', quantity: 4 },
  { id: 24, seller: { id: 3, name: 'GameVault' }, platform: 'Nintendo', price: '62.49', quantity: 5 },
]

for (const [platform, id, seller, override] of [
  ['Nintendo', 23, 'GameHub', false],
  ['Xbox', 22, 'PixelStore', false],
  ['Nintendo', 22, 'PixelStore', true],
]) test(`catalog platform ${platform} controls the initial offer, manual override=${override}`, async t => {
  const { page, state } = await setup(t, { width: 390, height: 844, guest: true })
  state.firstGameName = 'Minecraft'
  state.detailOffers = platformOffers
  await page.goto(base + '/?q=Minecraft&sort=price_asc')
  const sidebar = page.getByRole('complementary', { name: 'Katalogo filtrai' })
  await sidebar.getByRole('button', { name: 'Filtrai', exact: true }).click()
  await sidebar.getByRole('radio', { name: platform, exact: true }).click()
  await page.waitForURL(url => url.searchParams.get('platform') === platform)
  const game = page.getByRole('region', { name: 'Katalogo rezultatai' }).getByRole('link', { name: 'Minecraft', exact: true })
  await game.waitFor()
  await page.waitForFunction(expected => document.querySelector('a[aria-label="Minecraft"]')?.getAttribute('href') === '/games/1?platform=' + expected, platform)
  await game.click()
  await page.waitForURL('**/games/1?platform=' + platform)
  const offers = page.getByRole('region', { name: 'Pardavėjų pasiūlymai' })
  const xbox = offers.getByRole('radio', { name: 'PixelStore Xbox' })
  const nintendo = offers.getByRole('radio', { name: 'GameHub Nintendo' })
  await (platform === 'Nintendo' ? nintendo : xbox).and(page.locator(':checked')).waitFor()
  assert.deepEqual(await offers.getByRole('listitem').evaluateAll(rows => rows.map(row => row.querySelector('label span span')?.textContent)), ['PixelStore', 'GameHub', 'GameVault'])
  if (override) {
    await nintendo.focus()
    await nintendo.press('ArrowUp')
    await xbox.and(page.locator(':checked')).waitFor()
  }
  const chosen = offers.getByRole('listitem').filter({ hasText: seller })
  await chosen.getByText(id === 22 ? '€59.99' : '€61.24', { exact: true }).waitFor()
  await chosen.getByRole('button', { name: 'Į krepšelį' }).click()
  await page.getByRole('link', { name: 'Pirkinių krepšelis. Prekių: 1' }).waitFor()
  assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))), [{ offerId: id, quantity: 1 }])
  assert.equal(await (id === 22 ? xbox : nintendo).isChecked(), true)
  await page.getByRole('link', { name: '← Žaidimai' }).click()
  await page.waitForURL(u => u.searchParams.get('q') === 'Minecraft' && u.searchParams.get('platform') === platform && u.searchParams.get('sort') === 'price_asc')
})

test('direct, invalid, empty, duplicate and unmatched platform URLs keep the first offer', async t => {
  const { page, state } = await setup(t, { guest: true })
  state.detailOffers = platformOffers
  for (const query of ['', '?platform=Unknown', '?platform=', '?platform=Steam', '?platform=Nintendo&platform=Xbox']) {
    await page.goto(base + '/games/1' + query)
    const offers = page.getByRole('region', { name: 'Pardavėjų pasiūlymai' })
    await offers.getByRole('radio', { name: 'PixelStore Xbox' }).and(page.locator(':checked')).waitFor()
    assert.equal(await offers.getByRole('listitem').count(), 3)
  }
  state.detailOffers = []
  await page.goto(base + '/games/1?platform=Nintendo')
  await page.getByText('Pasiūlymų nėra', { exact: true }).waitFor()
})
