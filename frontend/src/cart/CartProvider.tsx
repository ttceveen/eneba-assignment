import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import type { Game } from '../api/games'
import { addCartItem, CART_STORAGE_KEY, limitCartQuantities, parseCart, removeCartItem, setCartQuantity } from './cart'
import { CartContext } from './context'

export default function CartProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState(() => {
    try { return parseCart(localStorage.getItem(CART_STORAGE_KEY)) } catch { return [] }
  })
  const [storageError, setStorageError] = useState(false)
  const knownStock = useRef(new Map<number, number>())

  // Report the outcome of external storage I/O; this is not derivable from render data.
  /* oxlint-disable react/set-state-in-effect */
  useEffect(() => {
    try {
      localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(items))
      setStorageError(false)
    } catch { setStorageError(true) }
  }, [items])
  /* oxlint-enable react/set-state-in-effect */

  useEffect(() => {
    const restore = (event: StorageEvent) => {
      try { if (event.storageArea !== localStorage) return } catch { return }
      if (event.key === CART_STORAGE_KEY || event.key === null) {
        setItems(limitCartQuantities(parseCart(event.newValue), knownStock.current))
      }
    }
    addEventListener('storage', restore)
    return () => removeEventListener('storage', restore)
  }, [])

  const addItem = useCallback((offerId: number, stock: number) => {
    knownStock.current.set(offerId, stock)
    setItems((current) => addCartItem(current, offerId, stock))
  }, [])
  const setQuantity = useCallback((offerId: number, quantity: number, stock?: number) => {
    setItems((current) => setCartQuantity(current, offerId, quantity, stock))
  }, [])
  const removeItem = useCallback((offerId: number) => {
    setItems((current) => removeCartItem(current, offerId))
  }, [])
  const rememberGame = useCallback((game: Game) => {
    const stock = new Map((game.offers ?? []).map((offer) => [offer.id, offer.quantity]))
    for (const offer of game.offers ?? []) {
      knownStock.current.set(offer.id, offer.quantity)
    }
    setItems((current) => limitCartQuantities(current, stock))
  }, [])
  const rememberStock = useCallback((stock: ReadonlyMap<number, number>) => {
    for (const [id, quantity] of stock) knownStock.current.set(id, quantity)
    setItems((current) => limitCartQuantities(current, stock))
  }, [])
  const value = useMemo(() => ({ items, storageError, addItem, setQuantity, removeItem, rememberGame, rememberStock }),
    [items, storageError, addItem, setQuantity, removeItem, rememberGame, rememberStock])

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}
