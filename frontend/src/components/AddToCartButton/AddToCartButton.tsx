import { useState } from 'react'
import type { Offer } from '../../api/games'
import { useCart } from '../../cart/context'
import styles from './AddToCartButton.module.css'

export default function AddToCartButton({ offer }: { offer: Offer }) {
  const { items, addItem } = useCart()
  const [added, setAdded] = useState(false)
  const quantity = items.find((item) => item.offerId === offer.id)?.quantity ?? 0
  const unavailable = offer.quantity < 1
  const atLimit = quantity >= offer.quantity

  return (
    <div className={styles.container}>
      <button type="button" disabled={unavailable || atLimit} onClick={() => {
        addItem(offer.id, offer.quantity)
        setAdded(true)
      }}>{unavailable ? 'Unavailable' : atLimit ? 'Stock limit reached' : 'Add to cart'}</button>
      <span role="status" className={styles.status}>{added ? 'Added to cart.' : ''}</span>
    </div>
  )
}
