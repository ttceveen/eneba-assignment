import assert from 'node:assert/strict'
import { before, after, test as browserTest } from 'node:test'
import { pathToFileURL } from 'node:url'

// Use an existing browser tool installation; this suite adds no project dependencies.
const modulePath = process.env.PLAYWRIGHT_MODULE_PATH
const { chromium } = await import(modulePath ? pathToFileURL(modulePath).href : 'playwright')
const base = (process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173').replace(/\/$/, '')
const key = 'eneba:auth-token'
const token = 'auth-provider-browser-fixture'
const user = { id: 1, first_name: 'Browser', last_name: 'Fixture', email: 'fixture@example.test', status: 'active' }
let browser
const test = (name, run) => browserTest(name, { timeout: 15000 }, run)
before(async () => { browser = await chromium.launch({ headless: true }) })
after(async () => { await browser?.close() })

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const json = (route, status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
const successMe = (route) => json(route, 200, { data: user })
const failureMe = (route) => json(route, 503, { message: 'Temporary account check failure.' })
const successLogout = (route) => json(route, 200, { message: 'Logged out.' })
const failureLogout = (route) => json(route, 503, { message: 'Temporary logout failure.' })

function heldResponse() {
  let start, release, finish
  const completed = new Promise((resolve) => { finish = resolve })
  const started = new Promise((resolve) => { start = resolve })
  const response = new Promise((resolve) => { release = resolve })
  return {
    started,
    completed,
    release: (status = 200, body = { data: user }) => release({ status, body }),
    handle: async (route) => {
      start()
      const { status, body } = await response
      // A replacement auth check may legitimately abort this request.
      try { await json(route, status, body) } catch (error) {
        if (!/closed|cancel|abort|intercept|invalid/i.test(String(error))) throw error
      } finally { finish() }
    },
  }
}

async function setup(t, authorized = true) {
  const context = await browser.newContext()
  const pending = []
  const errors = []
  const api = { me: successMe, logout: successLogout, meCalls: 0, logoutCalls: 0 }
  const page = await context.newPage()
  page.setDefaultTimeout(4000)
  page.on('pageerror', (error) => errors.push(error.message))
  await context.route(/^https?:\/\/[^/]+\/api\//, async (route) => {
    const path = new URL(route.request().url()).pathname
    if (path.endsWith('/me')) { api.meCalls++; await api.me(route) }
    else if (path.endsWith('/logout')) { api.logoutCalls++; await api.logout(route) }
    else if (path.endsWith('/login') || path.endsWith('/register')) await json(route, path.endsWith('/register') ? 201 : 200, { data: user, token, token_type: 'Bearer' })
    else if (path.endsWith('/games')) await json(route, 200, { data: [], meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 }, links: { first: '', last: '', prev: null, next: null } })
    else throw new Error(`Unexpected API path in auth-only test: ${path}`)
  })
  t.after(async () => {
    for (const response of pending) response.release()
    assert.deepEqual(errors, [], 'No uncaught JavaScript errors')
    await context.close()
  })
  await page.goto(base + '/cart')
  await page.getByRole('heading', { name: 'Your cart is empty', exact: true }).waitFor()
  if (authorized) {
    await page.evaluate(({ key, token }) => sessionStorage.setItem(key, token), { key, token })
    await page.reload()
    await page.getByRole('button', { name: 'Log out', exact: true }).waitFor()
  }
  function holdMe(next = successMe) {
    const held = heldResponse(); pending.push(held)
    api.me = (route) => { api.me = next; return held.handle(route) }
    return held
  }
  function holdLogout() {
    const held = heldResponse(); pending.push(held)
    api.logout = held.handle
    return held
  }
  async function enterOrders() {
    await page.getByRole('link', { name: 'Order history', exact: true }).click()
  }
  async function privateReady() {
    await page.getByRole('heading', { name: 'Order history', exact: true }).waitFor()
    assert.equal(await page.getByText('Checking your account...', { exact: true }).count(), 0)
  }
  async function guest() {
    await page.waitForURL('**/login?redirect=*')
    assert.equal(await page.evaluate((key) => sessionStorage.getItem(key), key), null)
    assert.equal(await page.getByRole('heading', { name: 'Order history', exact: true }).count(), 0)
    assert.equal(await page.getByText('Checking your account...', { exact: true }).count(), 0)
  }
  return { page, api, holdMe, holdLogout, enterOrders, privateReady, guest }
}

test('A: failed logout then old me success recovers with one fresh check', async (t) => {
  const f = await setup(t)
  const before = f.api.meCalls
  const old = f.holdMe(); f.api.logout = failureLogout
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor()
  old.release()
  await f.privateReady()
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), key), token)
  await pause(120)
  assert.equal(f.api.meCalls - before, 2, 'Only old and one recovery check')
})

test('B: successful logout ignores a later successful old me', async (t) => {
  const f = await setup(t)
  const old = f.holdMe()
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.guest()
  old.release(); await pause(120)
  await f.guest()
  assert.equal(f.api.logoutCalls, 1)
})

test('C: old me finishes before failed logout, recovery still converges', async (t) => {
  const f = await setup(t)
  const before = f.api.meCalls
  const old = f.holdMe(); const out = f.holdLogout()
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click(); await out.started
  old.release(); await old.completed
  out.release(503, { message: 'Temporary logout failure.' })
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor()
  await f.privateReady()
  assert.equal(f.api.meCalls - before, 2)
})

test('D: failed me and failed logout finish with retry UI, without automatic loop', async (t) => {
  const f = await setup(t)
  const before = f.api.meCalls
  const old = f.holdMe(failureMe); f.api.logout = failureLogout
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor()
  old.release(503, { message: 'Temporary account check failure.' })
  await f.page.getByRole('button', { name: 'Retry account check', exact: true }).waitFor()
  assert.equal(await f.page.getByText('Checking your account...', { exact: true }).count(), 0)
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), key), token)
  await pause(150); assert.equal(f.api.meCalls - before, 2)
  f.api.me = successMe
  await f.page.getByRole('button', { name: 'Retry account check', exact: true }).click()
  await f.privateReady()
})

test('E: retry logout succeeds while recovery me is pending; neither old reply restores user', async (t) => {
  const f = await setup(t)
  const recovery = heldResponse()
  t.after(() => recovery.release())
  const old = f.holdMe(recovery.handle); f.api.logout = failureLogout
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor(); await recovery.started
  f.api.logout = successLogout
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.guest()
  old.release(); recovery.release(); await pause(120)
  await f.guest(); assert.equal(f.api.logoutCalls, 2)
})

test('F: route changes during failed logout recover public and protected routes', async (t) => {
  const f = await setup(t)
  const old = f.holdMe(); const out = f.holdLogout()
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click(); await out.started
  await f.page.getByRole('link', { name: /Shopping cart,/ }).click()
  await f.page.getByRole('heading', { name: 'Your cart is empty', exact: true }).waitFor()
  out.release(503, { message: 'Temporary logout failure.' })
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor()
  await f.enterOrders(); await f.privateReady()
  old.release(); await pause(120); await f.privateReady()
})

test('successful logout also stays logged out when old me finishes before logout response', async (t) => {
  const f = await setup(t)
  const old = f.holdMe(); const out = f.holdLogout()
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click(); await out.started
  old.release(); await old.completed
  out.release(200, { message: 'Logged out.' })
  await f.guest(); await pause(120); await f.guest()
})

test('network failure during logout also triggers one recovery check', async (t) => {
  const f = await setup(t)
  const before = f.api.meCalls
  const old = f.holdMe(); f.api.logout = (route) => route.abort('failed')
  await f.enterOrders(); await old.started
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.page.getByRole('alert').filter({ hasText: 'Logout failed.' }).waitFor()
  old.release(); await f.privateReady()
  assert.equal(f.api.meCalls - before, 2)
})

test('normal register/login/logout/reload and protected redirect remain intact', async (t) => {
  const f = await setup(t, false)
  await f.page.getByRole('link', { name: 'Log in', exact: true }).click()
  await f.page.getByRole('link', { name: 'Create an account', exact: true }).click()
  await f.page.getByLabel('First name', { exact: true }).fill('Browser')
  await f.page.getByLabel('Last name', { exact: true }).fill('Fixture')
  await f.page.getByLabel('Email', { exact: true }).fill(user.email)
  await f.page.getByLabel('Password', { exact: true }).fill('BrowserFixture123')
  await f.page.getByRole('button', { name: 'Create account', exact: true }).click()
  await f.page.getByRole('button', { name: 'Log out', exact: true }).waitFor()
  await f.page.reload(); await f.page.getByRole('button', { name: 'Log out', exact: true }).waitFor()
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), key), token)
  await f.page.getByRole('button', { name: 'Log out', exact: true }).click()
  await f.page.getByRole('link', { name: 'Log in', exact: true }).waitFor()
  assert.equal(await f.page.evaluate((key) => sessionStorage.getItem(key), key), null)
  await f.page.goto(base + '/orders')
  await f.page.waitForURL('**/login?redirect=*')
  await f.page.getByLabel('Email', { exact: true }).fill(user.email)
  await f.page.getByLabel('Password', { exact: true }).fill('BrowserFixture123')
  await f.page.getByRole('button', { name: 'Log in', exact: true }).click()
  await f.privateReady()
})
