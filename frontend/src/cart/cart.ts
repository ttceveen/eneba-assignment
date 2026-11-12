export type CartItem = { offerId: number; quantity: number }
export const CART_STORAGE_KEY = 'eneba:cart'
export const MAX_CART_ITEMS = 20

const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0

export function parseCart(raw: string | null): CartItem[] {
  try {
    const value: unknown = JSON.parse(raw ?? '[]')
    if (!Array.isArray(value)) return []
    const unique = new Map<number, CartItem>()
    for (const item of value) {
      if (item && typeof item === 'object' && positive(item.offerId) && positive(item.quantity)) {
        if (!unique.has(item.offerId) && unique.size >= MAX_CART_ITEMS) continue
        const previous = unique.get(item.offerId)
        unique.set(item.offerId, { offerId: item.offerId, quantity: Math.max(previous?.quantity ?? 0, item.quantity) })
      }
    }
    return [...unique.values()]
  } catch { return [] }
}

export function addCartItem(items: CartItem[], offerId: number, stock: number): CartItem[] {
  if (!positive(offerId) || !positive(stock)) return items
  const current = items.find((item) => item.offerId === offerId)
  if (!current) return items.length >= MAX_CART_ITEMS ? items : [...items, { offerId, quantity: 1 }]
  const quantity = current.quantity >= stock ? stock : current.quantity + 1
  return setCartQuantity(items, offerId, quantity, stock)
}

export function setCartQuantity(items: CartItem[], offerId: number, quantity: number, stock?: number): CartItem[] {
  if (!positive(quantity) || (stock !== undefined && !positive(stock))) return items
  const next = stock === undefined ? quantity : Math.min(quantity, stock)
  return items.map((item) => item.offerId === offerId && item.quantity !== next ? { offerId, quantity: next } : item)
}

export function removeCartItem(items: CartItem[], offerId: number): CartItem[] {
  return items.filter((item) => item.offerId !== offerId)
}

export function limitCartQuantities(items: CartItem[], stock: ReadonlyMap<number, number>): CartItem[] {
  if (!items.some((item) => positive(stock.get(item.offerId)) && item.quantity > stock.get(item.offerId)!)) return items
  return items.map((item) => positive(stock.get(item.offerId)) && item.quantity > stock.get(item.offerId)!
    ? { offerId: item.offerId, quantity: stock.get(item.offerId)! } : item)
}

export function cartCount(items: CartItem[]): number {
  return items.reduce((total, item) => Math.min(Number.MAX_SAFE_INTEGER, total + item.quantity), 0)
}

export function priceInCents(price: string): bigint {
  const [whole, fraction] = price.split('.')
  return BigInt(whole) * 100n + BigInt(fraction)
}

const euros = new Intl.NumberFormat('en-IE')
export function formatCents(cents: bigint): string {
  return `€${euros.format(cents / 100n)}.${(cents % 100n).toString().padStart(2, '0')}`
}
