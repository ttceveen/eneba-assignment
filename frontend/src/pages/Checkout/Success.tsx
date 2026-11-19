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
      let errorContext: 'load-order' | 'load-keys' = 'load-order'
      try {
        const current = await run(token => getOrder(token, id, controller.signal))
        if (controller.signal.aborted || !user) return
        if (current.status !== 'paid') { navigate(`/orders/${id}`, { replace: true }); return }
        setOrder(current)
        try {
          completePurchase(user.id, id, current.items.map(item => ({ offerId: item.offer_id, quantity: item.quantity })))
          if (readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId === id) sessionStorage.removeItem(checkoutKey(user.id))
          setCartNotice('')
        } catch { setCartNotice('Už pirkinį sumokėta, tačiau dėl naršyklės saugyklos nepavyko atnaujinti krepšelio. Raktus rasite žemiau.') }
        errorContext = 'load-keys'
        const delivered = await run(token => getOrderKeys(token, id, controller.signal))
        if (!controller.signal.aborted) { setKeys(delivered); setMessage('') }
      } catch (error) { if (!controller.signal.aborted) setMessage(checkoutMessage(error, errorContext)) }
    })()
    return () => controller.abort()
  }, [id, user, run, navigate, completePurchase, retry])
  async function copy(id: number, value: string) {
    try { await navigator.clipboard.writeText(value); setCopied(id) }
    catch { setMessage('Nepavyko nukopijuoti rakto. Pažymėkite ir nukopijuokite jį rankiniu būdu.') }
  }
  return <main className={`${styles.main} ${styles.success}`}><h1>{order?.id === id && order.status === 'paid' ? message ? 'Mokėjimas patvirtintas' : 'Sėkmingai įsigijote' : message ? 'Nepavyko patikrinti pirkinio' : 'Tikrinamas pirkinys'}</h1>
    {!order && !message && <p role="status">Tikrinamas jūsų pirkinys…</p>}
    {order && <Summary order={order} />}
    {cartNotice && <p role="alert" className={styles.notice}>{cartNotice}</p>}
    {message && <p role="alert" className={styles.notice}>{message} <button onClick={() => setRetry(v => v + 1)}>{order ? 'Bandyti įkelti raktus dar kartą' : 'Bandyti įkelti užsakymą dar kartą'}</button></p>}
    {order && !keys && !message && <p role="status">Įkeliami įsigyti raktai…</p>}
    {keys && <section className={styles.panel} aria-label="Įsigyti raktai"><h2>Jūsų skaitmeniniai raktai</h2>{keys.items.map(item => <div key={item.order_item_id}>
      <h3>{order?.items.find(i => i.id === item.order_item_id)?.game.name ?? 'Įsigytas žaidimas'}</h3>
      {item.keys.map(key => <div className={styles.key} key={key.id}><code>{key.key_value}</code><button onClick={() => void copy(key.id, key.key_value)} aria-label={`Kopijuoti raktą ${key.id}`}>{copied === key.id ? 'Nukopijuota!' : 'Kopijuoti'}</button></div>)}
    </div>)}<p role="status">{copied !== null ? 'Raktas nukopijuotas į iškarpinę.' : ''}</p></section>}
    <Link to="/" className={styles.primary}>Tęsti apsipirkimą</Link><Link to="/cart">Grįžti į krepšelį</Link>
  </main>
}
