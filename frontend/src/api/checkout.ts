import { getApiBaseUrl, getResponse } from './games'
import type { CartItem } from '../cart/cart'

export type PaymentProvider = 'fake_card' | 'paypal'
export type Payment = { id: number; order_id: number; provider: PaymentProvider; external_id: string | null; redirect_url: string | null; idempotency_key: string | null; status: 'pending' | 'succeeded' | 'failed' | 'cancelled'; amount: string; currency: string; error_code: string | null; error_message: string | null }
export type OrderItem = { id: number; offer_id: number; quantity: number; unit_price: string; game: { id: number; name: string; img: string | null }; seller: { id: number; name: string } }
export type Order = { id: number; status: 'pending_payment' | 'paid' | 'expired' | 'cancelled'; items: OrderItem[]; total_amount: string; currency: string; reserved_until: string; payments: Payment[] }
export type OrderKeys = { order_id: number; items: { order_item_id: number; offer_id: number; keys: { id: number; key_value: string }[] }[] }
export class CheckoutError extends Error {
  status: number
  constructor(status: number) { super('Checkout request failed'); this.status = status }
}
async function request<T>(token: string, path: string, signal: AbortSignal, method = 'GET', body?: unknown, key?: string): Promise<T> {
  if (import.meta.env.PROD && new URL(getApiBaseUrl(), window.location.origin).protocol !== 'https:') throw new Error('HTTPS required')
  const response = await getResponse(path, signal, { method, cache: 'no-store', headers: {
    Authorization: `Bearer ${token}`, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}),
  }, ...(body ? { body: JSON.stringify(body) } : {}) })
  if (!response.ok) throw new CheckoutError(response.status)
  const payload = await response.json() as { data?: T }
  if (payload.data === undefined) throw new Error('Invalid checkout response')
  return payload.data
}
export const createOrder = (token: string, items: CartItem[], key: string, signal: AbortSignal) => request<Order>(token, '/checkout', signal, 'POST', { items: items.map(item => ({ offer_id: item.offerId, quantity: item.quantity })) }, key).then(checkedOrder)
export const getOrder = (token: string, id: number, signal: AbortSignal) => request<Order>(token, `/orders/${id}`, signal).then(checkedOrder)
export const cancelOrder = (token: string, id: number, signal: AbortSignal) => request<Order>(token, `/orders/${id}/cancel`, signal, 'POST').then(checkedOrder)
export const createPayment = (token: string, id: number, key: string, signal: AbortSignal, provider: PaymentProvider = 'fake_card') => request<Payment>(token, `/orders/${id}/payments`, signal, 'POST', { provider }, key).then(checkedPayment)
export const confirmPayPalPayment = (paypalOrderId: string, token: string, signal: AbortSignal) => {
  if (!isPayPalOrderId(paypalOrderId)) return Promise.reject(new Error('Invalid PayPal Order ID'))
  return request<Payment>(token, '/payments/paypal/confirm', AbortSignal.any([signal, AbortSignal.timeout(45_000)]), 'POST', { paypal_order_id: paypalOrderId }).then(checkedPayment).then(payment => {
    if (payment.provider !== 'paypal' || payment.external_id !== paypalOrderId) throw new Error('Invalid PayPal confirmation response')
    return payment
  })
}
export const isPayPalOrderId = (value: string | null): value is string => typeof value === 'string' && /^[A-Z0-9]{1,36}$/.test(value)
export function payPalApprovalUrl(value: string | null): string {
  if (!value || value.includes('\\')) throw new Error('Invalid PayPal Sandbox redirect')
  const url = new URL(value)
  if (url.protocol !== 'https:' || url.hostname !== 'www.sandbox.paypal.com' || url.port || url.username || url.password) throw new Error('Invalid PayPal Sandbox redirect')
  return url.href
}
export const simulatePayment = (token: string, id: number, result: 'success' | 'failure', signal: AbortSignal) => request<Payment>(token, `/payments/${id}/simulate`, signal, 'POST', { result }).then(checkedPayment)
export const getOrderKeys = (token: string, id: number, signal: AbortSignal) => request<OrderKeys>(token, `/orders/${id}/keys`, signal).then(checkedKeys)
export function checkoutMessage(error: unknown, context: 'create-order' | 'start-payment' | 'confirm' | 'load-order' | 'load-keys' | 'cancel' | 'redirect' = 'confirm'): string {
  const message = {
    'create-order': 'Nepavyko sukurti užsakymo. Pakartokite tą pačią užsakymo užklausą arba grįžkite į krepšelį.',
    'start-payment': 'Nepavyko pradėti mokėjimo. Mokėjimo bandymas gali jau egzistuoti. Prieš tęsdami atnaujinkite užsakymą.',
    confirm: 'Mokėjimo patvirtinimo rezultatas nežinomas. Prieš kartodami tą patį mokėjimą, atnaujinkite užsakymą. Nepradėkite naujo mokėjimo.',
    'load-order': 'Nepavyko įkelti užsakymo. Atnaujinkite užsakymą, kad matytumėte mokėjimo būseną.',
    'load-keys': 'Mokėjimas patvirtintas, tačiau nepavyko įkelti skaitmeninių raktų. Bandykite įkelti raktus dar kartą ir nemokėkite iš naujo.',
    cancel: 'Nepavyko patvirtinti atšaukimo. Prieš atlikdami kitą veiksmą, atnaujinkite užsakymą.',
    redirect: 'Nepavyko atidaryti „PayPal Sandbox“ patvirtinimo nuorodos. Prieš tęsdami atnaujinkite užsakymą.',
  }[context]
  let detail = ''
  if (error instanceof CheckoutError) {
    if (error.status === 401) detail = 'Prisijunkite dar kartą.'
    if (error.status === 403) detail = 'Šis mokėjimo būdas nepasiekiamas arba prieiga ribojama.'
    if (error.status === 404) detail = 'Šis užsakymas arba mokėjimas jūsų paskyrai nepasiekiamas.'
    if (error.status === 409) detail = 'Pasikeitė likutis arba rezervacija. Atnaujinkite užsakymą arba grįžkite į krepšelį ir patikrinkite turimą kiekį.'
    if (error.status === 422) detail = 'Patikrinkite krepšelį ir prekių kiekius, tada atnaujinkite likučius.'
  }
  return `${message}${detail ? ` ${detail}` : ''}`
}

function record(value: unknown): value is Record<string, unknown> { return value !== null && typeof value === 'object' && !Array.isArray(value) }
const positive = (value: unknown) => typeof value === 'number' && Number.isSafeInteger(value) && value > 0
const money = (value: unknown) => typeof value === 'string' && /^\d{1,10}\.\d{2}$/.test(value)
function checkedPayment(value: unknown): Payment {
  if (!record(value) || !positive(value.id) || !positive(value.order_id) || !['pending', 'succeeded', 'failed', 'cancelled'].includes(String(value.status)) || !money(value.amount) || !['fake_card', 'paypal'].includes(String(value.provider))
    || !(value.external_id === null || typeof value.external_id === 'string') || !(value.redirect_url === null || typeof value.redirect_url === 'string')) throw new Error('Invalid payment response')
  return value as Payment
}
function checkedOrder(value: unknown): Order {
  if (!record(value) || !positive(value.id) || !['pending_payment', 'paid', 'expired', 'cancelled'].includes(String(value.status)) || !money(value.total_amount) || typeof value.currency !== 'string'
    || typeof value.reserved_until !== 'string' || !Number.isFinite(Date.parse(value.reserved_until)) || !Array.isArray(value.items) || !Array.isArray(value.payments)) throw new Error('Invalid order response')
  for (const item of value.items) if (!record(item) || !positive(item.id) || !positive(item.offer_id) || !positive(item.quantity) || !money(item.unit_price) || !record(item.game) || typeof item.game.name !== 'string' || !record(item.seller) || typeof item.seller.name !== 'string') throw new Error('Invalid order item')
  value.payments.forEach(checkedPayment)
  return value as Order
}
function checkedKeys(value: unknown): OrderKeys {
  if (!record(value) || !positive(value.order_id) || !Array.isArray(value.items)) throw new Error('Invalid purchased keys response')
  for (const item of value.items) {
    if (!record(item) || !positive(item.order_item_id) || !Array.isArray(item.keys)) throw new Error('Invalid purchased keys item')
    for (const key of item.keys) if (!record(key) || !positive(key.id) || typeof key.key_value !== 'string') throw new Error('Invalid purchased key')
  }
  return value as OrderKeys
}
