import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { cancelOrder, checkoutMessage, createPayment, getOrder, simulatePayment, type Order } from '../../api/checkout'
import { useAuth } from '../../auth/context'
import { checkoutKey, paymentKey, readSession, saveSession, type CheckoutAttempt, type PaymentAttempt } from '../../checkout/session'
import { useConnection } from '../../checkout/useConnection'
import Summary from './Summary'
import styles from './Checkout.module.css'

export default function PaymentPage() {
  const id = Number(useParams().id), { user } = useAuth()
  const run = useConnection(), navigate = useNavigate()
  const [order, setOrder] = useState<Order | null>(null)
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [uncertain, setUncertain] = useState(false)
  const [desired, setDesired] = useState<'success' | 'failure'>(() => user ? readSession<PaymentAttempt>(paymentKey(user.id, id))?.desired ?? 'success' : 'success')
  const [now, setNow] = useState(Date.now)
  const active = useRef<AbortController | null>(null), locked = useRef(false), expiredChecked = useRef('')
  useEffect(() => { const timer = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(timer) }, [])
  const accept = useCallback((next: Order) => {
    setOrder(next)
    if (next.status === 'paid') navigate(`/orders/${id}/success`, { replace: true })
  }, [id, navigate])
  const load = useCallback(async () => {
    if (locked.current) return
    locked.current = true; setBusy(true); setMessage('')
    const controller = new AbortController(); active.current = controller
    try {
      const next = await run(token => getOrder(token, id, controller.signal))
      if (!controller.signal.aborted) { accept(next); setUncertain(false) }
    } catch (error) { if (!controller.signal.aborted) { setMessage(checkoutMessage(error)); setUncertain(true) } }
    finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }, [id, run, accept])
  useEffect(() => {
    let disposed = false
    void Promise.resolve().then(() => { if (!disposed) void load() })
    return () => { disposed = true; active.current?.abort(); locked.current = false }
  }, [load])
  const remaining = order ? Math.max(0, Math.ceil((Date.parse(order.reserved_until) - now) / 1000)) : 0
  useEffect(() => {
    if (!order || order.status !== 'pending_payment' || remaining > 0 || busy) return
    const key = `${order.id}:${order.reserved_until}`
    if (expiredChecked.current === key) return
    expiredChecked.current = key
    const timer = setTimeout(() => void load(), 0)
    return () => clearTimeout(timer)
  }, [order, remaining, busy, load])

  async function pay() {
    if (!user || locked.current || !order || order.status !== 'pending_payment' || remaining <= 0 || uncertain) return
    locked.current = true; setBusy(true); setMessage('')
    const controller = new AbortController(); active.current = controller
    try {
      let latest = await run(token => getOrder(token, id, controller.signal))
      if (controller.signal.aborted) return
      accept(latest)
      if (latest.status !== 'pending_payment') return
      const stored = readSession<PaymentAttempt>(paymentKey(user.id, id))
      let pending = latest.payments.find(p => p.status === 'pending')
      const same = stored && latest.payments.find(p => p.idempotency_key === stored.key)
      const attempt: PaymentAttempt = stored && (!same || same.status === 'pending') ? stored : { key: crypto.randomUUID(), desired }
      // A known pending attempt is resumed instead of creating a second one.
      if (!pending) {
        saveSession(paymentKey(user.id, id), attempt)
        pending = await run(token => createPayment(token, id, attempt.key, controller.signal))
      }
      if (controller.signal.aborted) return
      if (pending.status === 'pending') {
        const choice = stored && pending.idempotency_key === stored.key ? stored.desired : desired
        saveSession(paymentKey(user.id, id), { key: pending.idempotency_key ?? attempt.key, desired: choice, paymentId: pending.id })
        await run(token => simulatePayment(token, pending!.id, choice, controller.signal))
      }
      latest = await run(token => getOrder(token, id, controller.signal))
      if (!controller.signal.aborted) { accept(latest); setUncertain(false) }
    } catch (error) {
      if (!controller.signal.aborted) { setMessage(checkoutMessage(error)); setUncertain(true) }
    } finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }
  async function cancel() {
    if (locked.current) return
    locked.current = true; setBusy(true)
    const controller = new AbortController(); active.current = controller
    try { const next = await run(token => cancelOrder(token, id, controller.signal)); if (!controller.signal.aborted) accept(next) }
    catch (error) { if (!controller.signal.aborted) { setMessage(checkoutMessage(error)); setUncertain(true) } }
    finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }
  const latest = order?.payments[0]
  const saved = user ? readSession<PaymentAttempt>(paymentKey(user.id, id)) : null
  const savedPending = latest?.status === 'pending' && latest.idempotency_key === saved?.key
  return <main className={styles.main}><h1>Payment</h1>
    {message && <p role="alert" className={styles.notice}>{message}</p>}
    {!order ? <section className={styles.panel}><p role="status">{busy ? 'Loading order...' : 'Order could not be loaded.'}</p><button onClick={() => void load()} disabled={busy}>Refresh order</button></section> : <>
      <Summary order={order} />
      <section className={styles.panel}>
        {order.status === 'pending_payment' ? <>
          <p role="timer" aria-live="off">Reservation remaining: <strong>{Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}</strong></p>
          <p>This is a demo. No real money is charged. Do not enter bank card details.</p>
          {latest?.status === 'failed' && <p role="alert">Payment failed. Your reservation has not been extended. You can try again.</p>}
          <fieldset disabled={busy || remaining <= 0 || uncertain || savedPending}><legend>Demo payment result</legend>
            <label><input type="radio" name="result" checked={desired === 'success'} onChange={() => setDesired('success')} /> Successful payment</label>
            <label><input type="radio" name="result" checked={desired === 'failure'} onChange={() => setDesired('failure')} /> Failed payment</label>
          </fieldset>
          {savedPending && <p>The saved demo result will be retried for this pending attempt.</p>}
          <button className={styles.primary} disabled={busy || remaining <= 0 || uncertain} onClick={() => void pay()}>{busy ? 'Checking payment...' : latest?.status === 'failed' ? 'Try payment again' : latest?.status === 'pending' ? 'Continue demo payment' : 'Pay with demo card'}</button>
          <button disabled={busy} onClick={() => void cancel()}>Cancel order</button>
          {remaining === 0 && <p role="status">Reservation time reached. Checking the server status.</p>}
        </> : <p role="status">{order.status === 'expired' ? 'Your reservation has expired. Return to your cart to check availability.' : order.status === 'cancelled' ? 'This order has been cancelled.' : 'Payment confirmed.'}</p>}
        <button disabled={busy} onClick={() => void load()}>{uncertain ? 'Check payment status' : 'Refresh order'}</button>
        <Link to="/cart" onClick={() => { if (user && order.status !== 'pending_payment' && readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId === id) sessionStorage.removeItem(checkoutKey(user.id)) }}>Back to cart</Link>
      </section>
    </>}
  </main>
}
