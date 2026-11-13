import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useCart } from '../../cart/context'
import { formatCents, priceInCents } from '../../cart/cart'
import { resolveCartOffers, type CartOffer } from '../../cart/resolveOffers'
import GameCover from '../../components/GameCover/GameCover'
import styles from './Cart.module.css'

type CartData = { key: string; status: 'loading' | 'error' }
  | { key: string; status: 'ready'; offers: Map<number, CartOffer> }

export default function Cart() {
  const { items, storageError, adjustQuantity, removeItem, rememberStock, getSnapshot, syncRevision } = useCart()
  const [revision, setRevision] = useState(0)
  const ids = items.map((item) => item.offerId).sort((a, b) => a - b).join(',')
  const requestKey = `${ids}:${revision}:${syncRevision}`
  const [data, setData] = useState<CartData>({ key: requestKey, status: 'loading' })
  const navigate = useNavigate()
  const ready = data.key === requestKey && data.status === 'ready'
  const offers = ready && data.status === 'ready' ? data.offers : new Map<number, CartOffer>()
  const failed = data.key === requestKey && data.status === 'error'
  const allAvailable = ready && items.every((item) => offers.has(item.offerId))
  const total = items.reduce((sum, item) => {
    const detail = offers.get(item.offerId)
    return detail ? sum + priceInCents(detail.offer.price) * BigInt(Math.min(item.quantity, detail.offer.quantity)) : sum
  }, 0n)

  useEffect(() => {
    if (!ids) return
    const controller = new AbortController()
    const offerIds = ids.split(',').map(Number)
    const snapshot = getSnapshot()
    resolveCartOffers(offerIds, controller.signal)
      .then((resolved) => {
        if (controller.signal.aborted) return
        if (getSnapshot() !== snapshot) { setRevision((value) => value + 1); return }
        rememberStock(new Map([...resolved].map(([id, detail]) => [id, detail.offer.quantity])), snapshot)
        setData({ key: requestKey, status: 'ready', offers: resolved })
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setData({ key: requestKey, status: 'error' })
          controller.abort()
        }
      })
    return () => controller.abort()
  }, [ids, requestKey, rememberStock, getSnapshot])

  return (
    <main className={styles.main}>
      <div className={styles.heading}>
        <h1>Cart</h1>
        <Link to="/">← Continue shopping</Link>
      </div>
      {storageError && <p role="alert" className={styles.notice}>Browser storage is unavailable. Your cart works in this tab but cannot be saved.</p>}
      {items.length === 0 ? (
        <section className={styles.empty}>
          <h2>Your cart is empty</h2>
          <p>Choose a seller offer on a game page to add it to your cart.</p>
          <Link className={styles.primary} to="/">Continue shopping</Link>
        </section>
      ) : (
        <>
          {!ready && !failed && <p role="status">Loading cart offers...</p>}
          {failed && <p role="alert" className={styles.notice}>Failed to load cart offers. Your saved items have been kept. <button type="button" onClick={() => setRevision((value) => value + 1)}>Try again</button></p>}
          <div className={styles.layout}>
            <section aria-label="Cart items" aria-busy={!ready && !failed}>
              <ul className={styles.items}>
                {items.map((item) => {
                  const detail = offers.get(item.offerId)
                  const quantity = detail ? Math.min(item.quantity, detail.offer.quantity) : item.quantity
                  return (
                    <li className={styles.item} key={item.offerId} data-offer-id={item.offerId}>
                      <div className={styles.cover}><GameCover name={detail?.game.name ?? `Offer ${item.offerId}`} img={detail?.game.img} /></div>
                      <div className={styles.information}>
                        {detail ? (
                          <>
                            <h2><Link to={`/games/${detail.game.id}`}>{detail.game.name}</Link></h2>
                            <p>Seller: {detail.offer.seller.name}</p>
                            <p className={styles.stock}>Available: {detail.offer.quantity}</p>
                          </>
                        ) : (
                          <>
                            <h2>Offer #{item.offerId}</h2>
                            <p className={styles.unavailable}>{ready ? 'This offer is no longer available. Remove it to continue.' : failed ? 'Offer information could not be loaded.' : 'Loading offer information...'}</p>
                          </>
                        )}
                      </div>
                      <button className={styles.remove} type="button" aria-label="Remove item from cart" onClick={() => removeItem(item.offerId)}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
                          <path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6" />
                        </svg>
                      </button>
                      <div className={styles.controls}>
                        <div><span className={styles.label}>Unit price</span><span aria-label={`Unit price for offer ${item.offerId}`}>{detail ? formatCents(priceInCents(detail.offer.price)) : '—'}</span></div>
                        <div>
                          <span className={styles.label}>Quantity</span>
                          <div className={styles.quantity}>
                            <button type="button" aria-label={`Decrease quantity for offer ${item.offerId}`} disabled={!detail || quantity <= 1} onClick={() => adjustQuantity(item.offerId, -1, detail?.offer.quantity ?? 0)}>−</button>
                            <output aria-label={`Quantity for offer ${item.offerId}`}>{quantity}</output>
                            <button type="button" aria-label={`Increase quantity for offer ${item.offerId}`} disabled={!detail || quantity >= detail.offer.quantity} onClick={() => adjustQuantity(item.offerId, 1, detail?.offer.quantity ?? 0)}>+</button>
                          </div>
                        </div>
                        <div><span className={styles.label}>Item total</span><strong aria-label={`Item total for offer ${item.offerId}`}>{detail ? formatCents(priceInCents(detail.offer.price) * BigInt(quantity)) : '—'}</strong></div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            </section>
            <aside className={styles.summary} aria-label="Order summary">
              <h2>Order summary</h2>
              <div className={styles.total}><span>Total</span><strong aria-label="Cart total">{ready ? formatCents(total) : '—'}</strong></div>
              {ready && !allAvailable && <p className={styles.unavailable}>Unavailable offers are excluded from the total. Remove them before continuing.</p>}
              <p>Adding to cart does not reserve keys. Prices and availability will be checked by the server at checkout.</p>
              <button className={styles.primary} type="button" disabled={!allAvailable} onClick={() => navigate('/checkout')}>Proceed to checkout</button>
              <button type="button" className={styles.refresh} onClick={() => setRevision((value) => value + 1)}>Refresh prices</button>
              <Link className={styles.continue} to="/">Continue shopping</Link>
            </aside>
          </div>
        </>
      )}
    </main>
  )
}
