import { useState } from 'react'
import type { Offer } from '../../api/games'
import { MAX_CART_ITEMS } from '../../cart/cart'
import { useCart } from '../../cart/context'
import styles from './AddToCartButton.module.css'

export default function AddToCartButton({ offer }: { offer: Offer }) {
  const { items, addItem } = useCart()
  const [notice, setNotice] = useState<'added' | 'limit' | null>(null)
  const quantity = items.find((item) => item.offerId === offer.id)?.quantity ?? 0
  const full = items.length >= MAX_CART_ITEMS && !items.some((item) => item.offerId === offer.id)
  const unavailable = offer.quantity < 1
  const atLimit = quantity >= offer.quantity

  return (
    <div className={styles.container}>
      <button type="button" disabled={unavailable || atLimit} onClick={() => {
        setNotice(addItem(offer.id, offer.quantity) ? 'added' : 'limit')
      }}>{unavailable ? 'Unavailable' : atLimit ? 'Stock limit reached' : 'Add to cart'}</button>
      <span role="status" className={styles.status}>{notice === 'added' ? 'Added to cart.' : notice === 'limit' && full ? `Your cart can contain up to ${MAX_CART_ITEMS} offers. Remove an offer to add another.` : ''}</span>
    </div>
  )
}
