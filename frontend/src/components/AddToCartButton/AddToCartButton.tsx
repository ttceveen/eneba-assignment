import { useEffect, useRef, useState } from 'react'
import type { Offer } from '../../api/games'
import { MAX_CART_ITEMS } from '../../cart/cart'
import { useCart } from '../../cart/context'
import styles from './AddToCartButton.module.css'

type Props =
  | { offer: Offer; resolveOffer?: never }
  | { offer?: never; resolveOffer: (signal: AbortSignal) => Promise<Offer | null> }

export default function AddToCartButton({ offer, resolveOffer }: Props) {
  const { items, addItem } = useCart()
  const [notice, setNotice] = useState('')
  const [loading, setLoading] = useState(false)
  const pending = useRef<AbortController | null>(null)
  useEffect(() => () => pending.current?.abort(), [])

  const unavailable = offer !== undefined && offer.quantity < 1
  const atLimit = offer !== undefined && (items.find(item => item.offerId === offer.id)?.quantity ?? 0) >= offer.quantity

  function add(selected: Offer) {
    if ((items.find(item => item.offerId === selected.id)?.quantity ?? 0) >= selected.quantity) {
      setNotice('Pasiektas turimo kiekio limitas.')
      return
    }

    setNotice(addItem(selected.id, selected.quantity)
      ? 'Pridėta į krepšelį.'
      : `Krepšelyje gali būti ne daugiau kaip ${MAX_CART_ITEMS} pasiūlymų. Norėdami pridėti kitą, pašalinkite vieną pasiūlymą.`)
  }

  async function handleClick() {
    if (offer) {
      add(offer)
      return
    }
    if (!resolveOffer || pending.current) return

    const controller = new AbortController()
    pending.current = controller
    setLoading(true)
    setNotice('')
    try {
      const selected = await resolveOffer(controller.signal)
      if (selected && !controller.signal.aborted) add(selected)
    } catch {
      if (!controller.signal.aborted) setNotice('Nepavyko patikrinti pasiūlymo. Bandykite dar kartą.')
    } finally {
      pending.current = null
      if (!controller.signal.aborted) setLoading(false)
    }
  }

  return (
    <div className={styles.container}>
      <button type="button" disabled={unavailable || atLimit || loading} onClick={() => void handleClick()}>
        {resolveOffer ? (loading ? 'Tikrinama…' : 'Į krepšelį') : unavailable ? 'Nepasiekiama' : atLimit ? 'Pasiektas turimo kiekio limitas' : 'Į krepšelį'}
      </button>
      <span role="status" className={styles.status}>{notice}</span>
    </div>
  )
}
