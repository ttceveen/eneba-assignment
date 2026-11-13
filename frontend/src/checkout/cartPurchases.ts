import type { CartItem } from '../cart/cart'

const STORAGE = 'eneba:cart-purchases'
type Purchase = { token: string; userId: number; orderId?: number; remaining: CartItem[]; state: 'pending' | 'applying' | 'applied'; before?: string; after?: string }
function read(): Purchase[] {
  try {
    const records: unknown = JSON.parse(localStorage.getItem(STORAGE) ?? '[]')
    return Array.isArray(records) ? records.filter((r): r is Purchase => r && typeof r.token === 'string' && Array.isArray(r.remaining) && ['pending', 'applying', 'applied'].includes(r.state)) : []
  } catch { return [] }
}
function write(records: Purchase[]) { localStorage.setItem(STORAGE, JSON.stringify(records)) }
export function beginPurchase(token: string, userId: number, items: CartItem[]) {
  const records = read()
  if (!records.some(r => r.token === token && r.userId === userId)) write([...records, { token, userId, remaining: items.map(i => ({ ...i })), state: 'pending' }])
}
export function bindPurchase(token: string, userId: number, orderId: number) {
  write(read().map(r => r.token === token && r.userId === userId ? { ...r, orderId } : r))
}
// Decreases consume the originally checked-out units first. Later additions are never allocated.
export function recordCartChange(before: CartItem[], after: CartItem[]) {
  const records = read()
  if (!records.length) return
  write(records.map(record => record.state !== 'pending' ? record : { ...record, remaining: record.remaining.map(item => ({ ...item,
    quantity: Math.max(0, item.quantity - Math.max(0, (before.find(i => i.offerId === item.offerId)?.quantity ?? 0) - (after.find(i => i.offerId === item.offerId)?.quantity ?? 0))),
  })) }))
}
export function preparePurchase(userId: number, orderId: number, current: CartItem[], purchased: CartItem[]): CartItem[] | null {
  const records = read(), record = records.find(r => r.userId === userId && r.orderId === orderId)
  if (!record || record.state === 'applied') return null
  if (record.state === 'applying') {
    // Recover an interrupted two-key localStorage write; preserve ambiguous later edits.
    if (JSON.stringify(current) !== record.before) { record.state = 'applied'; write(records); return null }
    return JSON.parse(record.after ?? '[]') as CartItem[]
  }
  const next = current.flatMap(item => {
    const allocation = record.remaining.find(i => i.offerId === item.offerId)?.quantity ?? 0
    const paid = purchased.filter(i => i.offerId === item.offerId).reduce((sum, i) => sum + i.quantity, 0)
    const quantity = item.quantity - Math.min(item.quantity, allocation, paid)
    return quantity > 0 ? [{ ...item, quantity }] : []
  })
  record.state = 'applying'; record.before = JSON.stringify(current); record.after = JSON.stringify(next); write(records)
  return next
}
export function finishPurchase(userId: number, orderId: number) {
  write(read().map(r => r.userId === userId && r.orderId === orderId ? { ...r, state: 'applied' as const, before: undefined, after: undefined, remaining: [] } : r))
}
