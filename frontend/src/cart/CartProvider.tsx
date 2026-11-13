import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import type { Game } from '../api/games'
import { addCartItem, CART_STORAGE_KEY, limitCartQuantities, MAX_CART_ITEMS, parseCart, removeCartItem, setCartQuantity, type CartItem } from './cart'
import { CartContext } from './context'
import { recordCartChange, preparePurchase, finishPurchase } from '../checkout/cartPurchases'

export default function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState(() => {
    try { return parseCart(localStorage.getItem(CART_STORAGE_KEY)) } catch { return [] }
  })
  const [storageError, setStorageError] = useState(false)
  const [syncRevision, setSyncRevision] = useState(0)
  const currentItems = useRef(items)
  const unsaved = useRef(false)
  const changes = useRef(0)

  const readLatest = useCallback(() => {
    if (!unsaved.current) {
      try { return parseCart(localStorage.getItem(CART_STORAGE_KEY)) } catch { /* Keep in-memory edits if storage fails. */ }
    }
    return currentItems.current
  }, [])
  const adopt = useCallback((next: CartItem[], external = false) => {
    if (JSON.stringify(next) === JSON.stringify(currentItems.current)) return
    changes.current++
    currentItems.current = next
    setItems(next)
    if (external) setSyncRevision((value) => value + 1)
  }, [])
  const persist = useCallback((next: CartItem[], external = false, before?: CartItem[]) => {
    const normalized = parseCart(JSON.stringify(next))
    const raw = JSON.stringify(normalized)
    let saved = true
    try {
      if (before) recordCartChange(before, normalized)
      if (localStorage.getItem(CART_STORAGE_KEY) !== raw) localStorage.setItem(CART_STORAGE_KEY, raw)
      unsaved.current = false
      setStorageError(false)
    } catch { saved = false; unsaved.current = true; setStorageError(true) }
    adopt(normalized, external)
    return saved
  }, [adopt])
  const commit = useCallback((change: (current: CartItem[]) => CartItem[], expectedSnapshot?: string) => {
    const current = readLatest()
    // An API response may clamp only the cart it originally requested.
    if (expectedSnapshot !== undefined && JSON.stringify([changes.current, current]) !== expectedSnapshot) {
      adopt(current, true)
      return
    }
    persist(change(current), false, current)
  }, [readLatest, adopt, persist])
  const getSnapshot = useCallback(() => JSON.stringify([changes.current, readLatest()]), [readLatest])

  // Synchronize external storage I/O, never echo a render's stale state back to storage.
  /* oxlint-disable react/set-state-in-effect */
  useEffect(() => {
    const synchronize = () => {
      if (!unsaved.current) persist(readLatest(), true)
    }
    const restore = (event: StorageEvent) => {
      try { if (event.storageArea !== localStorage) return } catch { return }
      if (event.key === CART_STORAGE_KEY || event.key === null) {
        changes.current++ // Invalidate API work even when edits return to the same values.
        synchronize()
      }
    }
    synchronize() // Also migrate oversized/invalid legacy snapshots once.
    addEventListener('storage', restore)
    return () => removeEventListener('storage', restore)
  }, [persist, readLatest])
  /* oxlint-enable react/set-state-in-effect */

  const addItem = useCallback((offerId: number, stock: number) => {
    let accepted = true
    commit((current) => {
      if (!current.some((item) => item.offerId === offerId) && current.length >= MAX_CART_ITEMS) {
        accepted = false
        return current
      }
      return addCartItem(current, offerId, stock)
    })
    return accepted
  }, [commit])
  const setQuantity = useCallback((offerId: number, quantity: number, stock?: number) => {
    commit((current) => setCartQuantity(current, offerId, quantity, stock))
  }, [commit])
  const removeItem = useCallback((offerId: number) => {
    commit((current) => removeCartItem(current, offerId))
  }, [commit])
  const rememberStock = useCallback((stock: ReadonlyMap<number, number>, snapshot: string) => {
    commit((current) => limitCartQuantities(current, stock), snapshot)
  }, [commit])
  const rememberGame = useCallback((game: Game, snapshot: string) => {
    rememberStock(new Map((game.offers ?? []).map((offer) => [offer.id, offer.quantity])), snapshot)
  }, [rememberStock])
  const adjustQuantity = useCallback((offerId: number, delta: number, stock: number) => {
    commit(current => {
      const item = current.find(i => i.offerId === offerId)
      return item ? setCartQuantity(current, offerId, Math.max(1, Math.min(stock, item.quantity + delta)), stock) : current
    })
  }, [commit])
  const completePurchase = useCallback((userId: number, orderId: number, purchased: CartItem[]) => {
    if (unsaved.current && !persist(readLatest())) throw new Error('Cart storage is unavailable')
    const current = readLatest()
    const next = preparePurchase(userId, orderId, current, purchased)
    if (next) {
      if (!persist(next, false, current)) throw new Error('Cart storage is unavailable')
      finishPurchase(userId, orderId)
    }
  }, [readLatest, persist])
  const value = useMemo(() => ({ items, storageError, syncRevision, addItem, setQuantity, adjustQuantity, completePurchase, removeItem, rememberGame, rememberStock, getSnapshot }),
    [items, storageError, syncRevision, addItem, setQuantity, adjustQuantity, completePurchase, removeItem, rememberGame, rememberStock, getSnapshot])

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}
