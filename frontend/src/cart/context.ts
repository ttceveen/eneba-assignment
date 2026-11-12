import { createContext, useContext } from 'react'
import type { Game } from '../api/games'
import type { CartItem } from './cart'

type CartContextValue = {
  items: CartItem[]
  storageError: boolean
  addItem: (offerId: number, stock: number, gameId: number) => void
  setQuantity: (offerId: number, quantity: number, stock?: number) => void
  removeItem: (offerId: number) => void
  rememberGame: (game: Game) => void
  getGameHints: () => ReadonlyMap<number, number>
}

export const CartContext = createContext<CartContextValue | null>(null)

export function useCart(): CartContextValue {
  const value = useContext(CartContext)
  if (!value) throw new Error('CartProvider is required')
  return value
}
