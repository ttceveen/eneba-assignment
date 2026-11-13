import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { checkoutMessage, getOrder, getOrderKeys, type Order, type OrderKeys } from '../../api/checkout'
import { useAuth } from '../../auth/context'
import { useCart } from '../../cart/context'
import { checkoutKey, readSession, type CheckoutAttempt } from '../../checkout/session'
import { useConnection } from '../../checkout/useConnection'
import Summary from './Summary'
import styles from './Checkout.module.css'

export default function Success() {
  const id = Number(useParams().id), { user } = useAuth(), { completePurchase } = useCart()
  const run = useConnection(), navigate = useNavigate()
  const [order, setOrder] = useState<Order | null>(null), [keys, setKeys] = useState<OrderKeys | null>(null)
  const [cartNotice, setCartNotice] = useState('')
  const [message, setMessage] = useState(''), [retry, setRetry] = useState(0), [copied, setCopied] = useState<number | null>(null)
  useEffect(() => {
    const controller = new AbortController()
    void (async () => {
      try {
        const current = await run(token => getOrder(token, id, controller.signal))
        if (controller.signal.aborted || !user) return
        if (current.status !== 'paid') { navigate(`/orders/${id}`, { replace: true }); return }
        setOrder(current)
        try {
          completePurchase(user.id, id, current.items.map(item => ({ offerId: item.offer_id, quantity: item.quantity })))
          if (readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId === id) sessionStorage.removeItem(checkoutKey(user.id))
          setCartNotice('')
        } catch { setCartNotice('Your purchase is paid, but browser storage prevented the cart update. Your keys are still available below.') }
        const delivered = await run(token => getOrderKeys(token, id, controller.signal))
        if (!controller.signal.aborted) { setKeys(delivered); setMessage('') }
      } catch (error) { if (!controller.signal.aborted) setMessage(checkoutMessage(error)) }
    })()
    return () => controller.abort()
  }, [id, user, run, navigate, completePurchase, retry])
  async function copy(id: number, value: string) {
    try { await navigator.clipboard.writeText(value); setCopied(id) }
    catch { setMessage('Copy is unavailable. Select and copy the key manually.') }
  }
  return <main className={styles.main}><h1>Purchase successful</h1>
    {!order && !message && <p role="status">Checking your purchase...</p>}
    {order && <Summary order={order} />}
    {cartNotice && <p role="alert" className={styles.notice}>{cartNotice}</p>}
    {message && <p role="alert" className={styles.notice}>{message} <button onClick={() => setRetry(v => v + 1)}>Retry loading keys</button></p>}
    {order && !keys && !message && <p role="status">Loading purchased keys...</p>}
    {keys && <section className={styles.panel} aria-label="Purchased keys"><h2>Your digital keys</h2>{keys.items.map(item => <div key={item.order_item_id}>
      <h3>{order?.items.find(i => i.id === item.order_item_id)?.game.name ?? 'Purchased game'}</h3>
      {item.keys.map(key => <div className={styles.key} key={key.id}><code>{key.key_value}</code><button onClick={() => void copy(key.id, key.key_value)} aria-label={`Copy key ${key.id}`}>{copied === key.id ? 'Copied!' : 'Copy'}</button></div>)}
    </div>)}<p role="status">{copied !== null ? 'Key copied to clipboard.' : ''}</p></section>}
    <Link to="/">Continue shopping</Link><Link to="/cart">Back to cart</Link>
  </main>
}
