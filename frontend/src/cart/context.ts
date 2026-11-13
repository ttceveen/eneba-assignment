import { createContext, useContext } from 'react'
import type { Game } from '../api/games'
import type { CartItem } from './cart'

type CartContextValue = {
  items: CartItem[]
  storageError: boolean
  syncRevision: number
  getSnapshot: () => string
  addItem: (offerId: number, stock: number) => boolean
  setQuantity: (offerId: number, quantity: number, stock?: number) => void
  adjustQuantity: (offerId: number, delta: number, stock: number) => void
  completePurchase: (userId: number, orderId: number, purchased: CartItem[]) => void
  removeItem: (offerId: number) => void
  rememberGame: (game: Game, snapshot: string) => void
  rememberStock: (stock: ReadonlyMap<number, number>, snapshot: string) => void
}

export const CartContext = createContext<CartContextValue | null>(null)

export function useCart(): CartContextValue {
  const value = useContext(CartContext)
  if (!value) throw new Error('CartProvider is required')
  return value
}
