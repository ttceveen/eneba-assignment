import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { stripTypeScriptTypes } from 'node:module'
import test from 'node:test'

const source = await readFile(new URL('../src/cart/cart.ts', import.meta.url), 'utf8')
const { parseCart, addCartItem, setCartQuantity, removeCartItem, limitCartQuantities, cartCount, priceInCents, formatCents } = await import(
  `data:text/javascript;base64,${Buffer.from(stripTypeScriptTypes(source)).toString('base64')}`)

test('adds an offer and increments only the same seller offer', () => {
  let items = addCartItem([], 10, 3)
  assert.deepEqual(items, [{ offerId: 10, quantity: 1 }])
  items = addCartItem(items, 10, 3)
  assert.deepEqual(items, [{ offerId: 10, quantity: 2 }])
  items = addCartItem(items, 11, 5)
  assert.deepEqual(items, [{ offerId: 10, quantity: 2 }, { offerId: 11, quantity: 1 }])
  assert.equal(cartCount(items), 3)
})
test('repeat add cannot exceed known stock and unavailable stock is rejected', () => {
  assert.deepEqual(addCartItem([{ offerId: 1, quantity: 3 }], 1, 3), [{ offerId: 1, quantity: 3 }])
  for (const stock of [0, -1, 1.5, NaN, Infinity]) assert.deepEqual(addCartItem([], 1, stock), [])
})
test('quantity changes respect minimum, whole numbers and current API maximum', () => {
  const items = [{ offerId: 1, quantity: 2 }]
  assert.deepEqual(setCartQuantity(items, 1, 3, 5), [{ offerId: 1, quantity: 3 }])
  assert.deepEqual(setCartQuantity(items, 1, 99, 5), [{ offerId: 1, quantity: 5 }])
  for (const quantity of [0, -1, 0.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
    assert.deepEqual(setCartQuantity(items, 1, quantity, 5), items)
  }
  assert.deepEqual(setCartQuantity(items, 1, 3, 0), items)
})
test('removes a single seller position and supports an empty cart', () => {
  const items = [{ offerId: 1, quantity: 2 }, { offerId: 2, quantity: 1 }]
  assert.deepEqual(removeCartItem(items, 1), [{ offerId: 2, quantity: 1 }])
  assert.deepEqual(removeCartItem(removeCartItem(items, 1), 2), [])
  assert.equal(cartCount([]), 0)
})
test('restores saved cart and retains only offerId and quantity', () => {
  const items = parseCart('[{"offerId":1,"quantity":2,"price":"0.01","seller":"forged"}]')
  assert.deepEqual(items, [{ offerId: 1, quantity: 2 }])
  assert.equal(JSON.stringify(items), '[{"offerId":1,"quantity":2}]')
})
test('damaged, obsolete and invalid data are handled without throwing', () => {
  for (const raw of [null, '', '{bad', 'null', '123', '{"items":[]}', '"cart"']) assert.deepEqual(parseCart(raw), [])
  assert.deepEqual(parseCart('[null,{},[],{"offerId":"1","quantity":1},{"offerId":1,"quantity":0},{"offerId":-1,"quantity":1},{"offerId":2,"quantity":2.5},{"offerId":3,"quantity":1}]'), [{ offerId: 3, quantity: 1 }])
})
test('duplicate persisted offers are coalesced without summing corrupted duplicates', () => {
  assert.deepEqual(parseCart('[{"offerId":1,"quantity":2},{"offerId":1,"quantity":3}]'), [{ offerId: 1, quantity: 3 }])
})
test('money uses exact integer cents for quantities, totals and zero prices', () => {
  assert.equal(priceInCents('19.99'), 1999n)
  assert.equal(formatCents(priceInCents('19.99') * 3n), '€59.97')
  assert.equal(formatCents(priceInCents('0.10') + priceInCents('0.20')), '€0.30')
  assert.equal(formatCents(priceInCents('0.00')), '€0.00')
  assert.equal(formatCents(123456n), '€1,234.56')
})
test('operations do not mutate the input state', () => {
  const items = Object.freeze([Object.freeze({ offerId: 1, quantity: 1 })])
  addCartItem(items, 1, 3); setCartQuantity(items, 1, 2, 3); removeCartItem(items, 1)
  assert.deepEqual(items, [{ offerId: 1, quantity: 1 }])
})

test('fresh stock limits restored quantities without deleting unavailable positions', () => {
  const items = [{ offerId: 1, quantity: 99 }, { offerId: 2, quantity: 2 }, { offerId: 3, quantity: 1 }]
  assert.deepEqual(limitCartQuantities(items, new Map([[1, 3], [2, 0]])), [
    { offerId: 1, quantity: 3 }, { offerId: 2, quantity: 2 }, { offerId: 3, quantity: 1 },
  ])
  assert.equal(limitCartQuantities(items, new Map()), items)
})
