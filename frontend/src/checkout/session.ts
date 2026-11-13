import { parseCart, type CartItem } from '../cart/cart'

export type CheckoutAttempt = { key: string; items: CartItem[]; orderId?: number; tracked?: boolean }
export type PaymentAttempt = { key: string; desired: 'success' | 'failure'; paymentId?: number }
export function readSession<T>(key: string): T | null {
  try { return JSON.parse(sessionStorage.getItem(key) ?? 'null') as T | null } catch { return null }
}
export function saveSession(key: string, value: unknown) { sessionStorage.setItem(key, JSON.stringify(value)) }
export const checkoutKey = (userId: number) => `eneba:checkout:${userId}`
export const paymentKey = (userId: number, orderId: number) => `eneba:payment:${userId}:${orderId}`
export function checkoutAttempt(userId: number, current: CartItem[]): CheckoutAttempt {
  const saved = readSession<CheckoutAttempt>(checkoutKey(userId))
  if (saved && typeof saved.key === 'string' && Array.isArray(saved.items) && parseCart(JSON.stringify(saved.items)).length > 0) return saved
  const attempt = { key: crypto.randomUUID(), items: current }
  saveSession(checkoutKey(userId), attempt)
  return attempt
}
