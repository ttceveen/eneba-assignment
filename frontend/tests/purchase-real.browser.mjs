import assert from 'node:assert/strict'
import test from 'node:test'
import { pathToFileURL } from 'node:url'
const api = process.env.CHECKOUT_SANDBOX_API_URL

test('real isolated PostgreSQL: guest cart, register, failed payment, retry, keys, copy and reload', { skip: !api, timeout: 45000 }, async () => {
  assert.ok(/^http:\/\/(localhost|127\.0\.0\.1):8011$/.test(api), 'Only the disposable local sandbox is allowed')
  const { chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH ? pathToFileURL(process.env.PLAYWRIGHT_MODULE_PATH).href : 'playwright')
  const browser = await chromium.launch({ headless: true })
  const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] })
  const errors = []
  try {
    await context.route(/^http:\/\/(localhost|127\.0\.0\.1):8000\/api\//, route => route.continue({ url: route.request().url().replace(/^http:\/\/[^/]+/, api) }))
    const page = await context.newPage(); page.setDefaultTimeout(12000)
    page.on('pageerror', error => errors.push(error.message))
    const base = process.env.FRONTEND_BASE_URL ?? 'http://localhost:5173'
    await page.goto(base + '/games/1')
    await page.getByRole('heading', { name: 'Synthetic Browser Checkout Fixture', exact: true }).waitFor()
    await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
    await page.getByRole('button', { name: 'Add to cart', exact: true }).click()
    await page.goto(base + '/cart'); await page.getByRole('button', { name: 'Proceed to checkout', exact: true }).click()
    await page.waitForURL('**/login?redirect=*')
    await page.getByRole('link', { name: 'Create an account', exact: true }).click()
    await page.getByLabel('First name', { exact: true }).fill('Browser')
    await page.getByLabel('Last name', { exact: true }).fill('Fixture')
    await page.getByLabel('Email', { exact: true }).fill(`browser-${Date.now()}@example.test`)
    await page.getByLabel('Password', { exact: true }).fill('SyntheticBrowser123')
    await page.getByRole('button', { name: 'Create account', exact: true }).click()
    await page.waitForURL('**/orders/1')
    await page.getByLabel('Failed payment', { exact: true }).check()
    await page.getByRole('button', { name: 'Pay with demo card', exact: true }).click()
    await page.getByRole('button', { name: 'Try payment again', exact: true }).waitFor()
    assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))[0].quantity), 2)
    await page.getByLabel('Successful payment', { exact: true }).check()
    await page.getByRole('button', { name: 'Try payment again', exact: true }).click()
    await page.waitForURL('**/orders/1/success')
    await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
    assert.equal(await page.locator('code').count(), 2)
    await page.getByRole('button', { name: 'Copy key 1', exact: true }).click()
    await page.getByRole('button', { name: 'Copy key 1', exact: true }).filter({ hasText: 'Copied!' }).waitFor()
    assert.ok(await page.evaluate(async () => (await navigator.clipboard.readText()).startsWith('BROWSER-DEMO-NOT-VALID-')))
    assert.ok(await page.evaluate(() => !JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]).includes('BROWSER-DEMO-NOT-VALID-')))
    assert.deepEqual(await page.evaluate(() => JSON.parse(localStorage.getItem('eneba:cart'))), [])
    await page.reload(); await page.getByRole('heading', { name: 'Your digital keys', exact: true }).waitFor()
    assert.equal(await page.locator('code').count(), 2)
    assert.deepEqual(errors, [])
  } finally { await context.close(); await browser.close() }
})
