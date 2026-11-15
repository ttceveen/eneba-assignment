import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import type { Game } from '../api/games'
import { addCartItem, CART_STORAGE_KEY, limitCartQuantities, MAX_CART_ITEMS, parseCart, removeCartItem, setCartQuantity, type CartItem } from './cart'
import { useAuth } from '../auth/context'
import { CartContext } from './context'
import { recordCartChange, preparePurchase, finishPurchase } from '../checkout/cartPurchases'

const OWNER_KEY = 'eneba:cart-owner'
function readOwner(): number | null | undefined {
  try {
    const raw = localStorage.getItem(OWNER_KEY)
    const id = Number(raw)
    return raw !== null && Number.isSafeInteger(id) && id > 0 ? id : null
  } catch { return undefined }
}

export default function CartProvider({ children }: { children: ReactNode }) {
  const { user, loading, error, logoutRevision } = useAuth()
  const userId = user?.id ?? null
  const [cartOwner, setCartOwner] = useState<number | null>(() => readOwner() ?? null)
  const owner = useRef(cartOwner)
  const actingUser = useRef<number | null>(null)
  const observedOwner = useRef(cartOwner)
  const handledLogout = useRef(logoutRevision)
  const [cartSession, setCartSession] = useState(0)
  const [items, setItems] = useState(() => {
    try { return parseCart(localStorage.getItem(CART_STORAGE_KEY)) } catch { return [] }
  })
  const [stockChanges, setStockChanges] = useState<{ offerId: number; from: number; to: number }[]>([])
  const clearStockChange = useCallback((offerId: number) => {
    setStockChanges(current => current.some(change => change.offerId === offerId) ? current.filter(change => change.offerId !== offerId) : current)
  }, [])
  const [storageError, setStorageError] = useState(false)
  const [syncRevision, setSyncRevision] = useState(0)
  const currentItems = useRef(items)
  const unsaved = useRef(false)
  const changes = useRef(0)

  const identityChanged = useCallback(() => actingUser.current !== null && owner.current !== null && actingUser.current !== owner.current, [])
  const ownsStorage = useCallback(() => {
    const stored = readOwner()
    return stored === undefined || stored === owner.current
  }, [])
  const readLatest = useCallback(() => {
    if (identityChanged()) return []
    if (!unsaved.current) {
      if (!ownsStorage()) return []
      try { return parseCart(localStorage.getItem(CART_STORAGE_KEY)) } catch { /* Keep in-memory edits if storage fails. */ }
    }
    return currentItems.current
  }, [identityChanged, ownsStorage])
  const adopt = useCallback((next: CartItem[], external = false) => {
    if (JSON.stringify(next) === JSON.stringify(currentItems.current)) return
    changes.current++
    currentItems.current = next
    setItems(next)
    if (external) setSyncRevision((value) => value + 1)
  }, [])
  const persist = useCallback((next: CartItem[], external = false, before?: CartItem[]) => {
    if (identityChanged()) return false
    const normalized = parseCart(JSON.stringify(next))
    if (!ownsStorage()) {
      // Another account owns the shared storage; never read or overwrite its items.
      adopt(unsaved.current ? normalized : [], external)
      return false
    }
    const raw = JSON.stringify(normalized)
    let saved = true
    try {
      if (before) recordCartChange(before, normalized)
      const stored = localStorage.getItem(CART_STORAGE_KEY)
      if (stored !== raw && (normalized.length > 0 || stored !== null)) localStorage.setItem(CART_STORAGE_KEY, raw)
      unsaved.current = false
      setStorageError(false)
    } catch { saved = false; unsaved.current = true; setStorageError(true) }
    adopt(normalized, external)
    return saved
  }, [adopt, identityChanged, ownsStorage])
  const commit = useCallback((change: (current: CartItem[]) => CartItem[], expectedSnapshot?: string) => {
    if (identityChanged()) return
    const current = readLatest()
    // An API response may clamp only the cart it originally requested.
    if (expectedSnapshot !== undefined && JSON.stringify([changes.current, current]) !== expectedSnapshot) {
      adopt(current, true)
      return
    }
    persist(change(current), false, current)
  }, [readLatest, adopt, persist, identityChanged])
  const getSnapshot = useCallback(() => JSON.stringify([changes.current, readLatest()]), [readLatest])

  const clearCart = useCallback((removeStored: boolean) => {
    changes.current++ // Invalidate snapshots even if the cart was already empty.
    let removed = true
    if (removeStored) {
      try { recordCartChange(currentItems.current, []) } catch { /* Clearing must still proceed. */ }
      try { localStorage.removeItem(CART_STORAGE_KEY) } catch { removed = false }
    }
    unsaved.current = !removed
    currentItems.current = []
    setItems([])
    setStockChanges([])
    setStorageError(!removed)
    setSyncRevision(value => value + 1)
    setCartSession(value => value + 1) // Dispose old UI state and abort its in-flight requests.
    return removed
  }, [])

  // Resolve identity before paint. Loading/error/implicit auth loss is not Logout.
  /* oxlint-disable react/set-state-in-effect */
  useLayoutEffect(() => {
    actingUser.current = userId
    if (handledLogout.current !== logoutRevision) {
      handledLogout.current = logoutRevision
      const removed = clearCart(true)
      owner.current = null
      setCartOwner(null)
      if (removed) {
        try { localStorage.removeItem(OWNER_KEY) } catch { setStorageError(true) }
      }
      observedOwner.current = readOwner() ?? null
    }
    if (loading || error || userId === null) return
    const storedOwner = readOwner()
    const changed = (owner.current !== null && owner.current !== userId) || (storedOwner !== undefined && storedOwner !== null && storedOwner !== userId)
    const removed = !changed || clearCart(true)
    owner.current = userId
    setCartOwner(userId)
    if (removed) {
      try {
        if (readOwner() !== userId) localStorage.setItem(OWNER_KEY, String(userId))
      } catch { unsaved.current = true; setStorageError(true) }
    }
    observedOwner.current = readOwner() ?? null
  }, [userId, loading, error, logoutRevision, clearCart])
  /* oxlint-enable react/set-state-in-effect */

  // Synchronize external storage I/O, never echo a render's stale state back to storage.
  /* oxlint-disable react/set-state-in-effect */
  useEffect(() => {
    const synchronize = () => {
      if (!ownsStorage()) {
        const stored = readOwner() ?? null
        if (observedOwner.current !== stored) {
          observedOwner.current = stored
          clearCart(false)
        }
        return
      }
      observedOwner.current = owner.current
      if (!unsaved.current) persist(readLatest(), true)
    }
    const restore = (event: StorageEvent) => {
      try { if (event.storageArea !== localStorage) return } catch { return }
      if (event.key === CART_STORAGE_KEY || event.key === OWNER_KEY || event.key === null) {
        changes.current++ // Invalidate API work even when edits return to the same values.
        synchronize()
      }
    }
    synchronize() // Also migrate oversized/invalid legacy snapshots once.
    addEventListener('storage', restore)
    return () => removeEventListener('storage', restore)
  }, [persist, readLatest, ownsStorage, clearCart])
  /* oxlint-enable react/set-state-in-effect */

  const addItem = useCallback((offerId: number, stock: number) => {
    clearStockChange(offerId)
    let accepted = true
    commit((current) => {
      if (!current.some((item) => item.offerId === offerId) && current.length >= MAX_CART_ITEMS) {
        accepted = false
        return current
      }
      return addCartItem(current, offerId, stock)
    })
    return accepted
  }, [commit, clearStockChange])
  const setQuantity = useCallback((offerId: number, quantity: number, stock?: number) => {
    clearStockChange(offerId)
    commit((current) => setCartQuantity(current, offerId, quantity, stock))
  }, [commit, clearStockChange])
  const removeItem = useCallback((offerId: number) => {
    clearStockChange(offerId)
    commit((current) => removeCartItem(current, offerId))
  }, [commit, clearStockChange])
  const rememberStock = useCallback((stock: ReadonlyMap<number, number>, snapshot: string) => {
    commit((current) => {
      const next = limitCartQuantities(current, stock)
      const reductions = next.flatMap(item => {
        const before = current.find(previous => previous.offerId === item.offerId)
        return before && before.quantity > item.quantity ? [{ offerId: item.offerId, from: before.quantity, to: item.quantity }] : []
      })
      if (reductions.length) setStockChanges(previous => [...previous.filter(change => !reductions.some(reduction => reduction.offerId === change.offerId)), ...reductions])
      return next
    }, snapshot)
  }, [commit])
  const rememberGame = useCallback((game: Game, snapshot: string) => {
    rememberStock(new Map((game.offers ?? []).map((offer) => [offer.id, offer.quantity])), snapshot)
  }, [rememberStock])
  const adjustQuantity = useCallback((offerId: number, delta: number, stock: number) => {
    clearStockChange(offerId)
    commit(current => {
      const item = current.find(i => i.offerId === offerId)
      return item ? setCartQuantity(current, offerId, Math.max(1, Math.min(stock, item.quantity + delta)), stock) : current
    })
  }, [commit, clearStockChange])
  const completePurchase = useCallback((userId: number, orderId: number, purchased: CartItem[]) => {
    if (identityChanged() || owner.current !== userId) return
    if (unsaved.current && !persist(readLatest())) throw new Error('Cart storage is unavailable')
    const current = readLatest()
    const next = preparePurchase(userId, orderId, current, purchased)
    if (next) {
      if (!persist(next, false, current)) throw new Error('Cart storage is unavailable')
      finishPurchase(userId, orderId)
    }
  }, [readLatest, persist, identityChanged])
  const hidePreviousCart = userId !== null && cartOwner !== null && userId !== cartOwner
  const value = useMemo(() => ({ items: hidePreviousCart ? [] : items, stockChanges: hidePreviousCart ? [] : stockChanges, storageError, syncRevision, addItem, setQuantity, adjustQuantity, completePurchase, removeItem, rememberGame, rememberStock, getSnapshot }),
    [hidePreviousCart, items, stockChanges, storageError, syncRevision, addItem, setQuantity, adjustQuantity, completePurchase, removeItem, rememberGame, rememberStock, getSnapshot])

  return <CartContext.Provider value={value}><Fragment key={cartSession}>{children}</Fragment></CartContext.Provider>
}
