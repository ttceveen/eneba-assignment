import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { cancelOrder, checkoutMessage, createPayment, getOrder, payPalApprovalUrl, simulatePayment, type Order, type PaymentProvider } from '../../api/checkout'
import { useAuth } from '../../auth/context'
import { checkoutKey, paymentKey, readSession, saveSession, type CheckoutAttempt, type PaymentAttempt } from '../../checkout/session'
import { useConnection } from '../../checkout/useConnection'
import Summary from './Summary'
import { formatCents, priceInCents } from '../../cart/cart'
import styles from './Checkout.module.css'

export default function PaymentPage() {
  const id = Number(useParams().id), { user } = useAuth()
  const run = useConnection(), navigate = useNavigate()
  const [order, setOrder] = useState<Order | null>(null)
  const [detailsExpanded, setDetailsExpanded] = useState(false)
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [uncertain, setUncertain] = useState(false)
  const [desired, setDesired] = useState<'success' | 'failure'>(() => user ? readSession<PaymentAttempt>(paymentKey(user.id, id))?.desired ?? 'success' : 'success')
  const [provider, setProvider] = useState<PaymentProvider>(() => user ? readSession<PaymentAttempt>(paymentKey(user.id, id))?.provider ?? 'fake_card' : 'fake_card')
  const pendingPayPal = order?.payments.find(p => p.status === 'pending' && p.provider === 'paypal')
  const pendingPayment = order?.payments.find(p => p.status === 'pending')
  const method = pendingPayment?.provider ?? provider
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
    } catch (error) { if (!controller.signal.aborted) { setMessage(checkoutMessage(error, 'load-order')); setUncertain(true) } }
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
    if (!user || locked.current || !order || order.status !== 'pending_payment' || (remaining <= 0 && !pendingPayPal?.external_id) || uncertain) return
    locked.current = true; setBusy(true); setMessage('')
    const controller = new AbortController(); active.current = controller
    let errorContext: 'load-order' | 'start-payment' | 'confirm' = 'load-order'
    try {
      let latest = await run(token => getOrder(token, id, controller.signal))
      if (controller.signal.aborted) return
      accept(latest)
      if (latest.status !== 'pending_payment') return
      const stored = readSession<PaymentAttempt>(paymentKey(user.id, id))
      let pending = latest.payments.find(p => p.status === 'pending')
      const same = stored && latest.payments.find(p => p.idempotency_key === stored.key)
      const chosen = pending?.provider ?? provider
      const attempt: PaymentAttempt = stored && (stored.provider ?? 'fake_card') === chosen && (!same || same.status === 'pending') ? stored : { key: crypto.randomUUID(), desired, provider: chosen }
      let created = false
      // Resume the same unresolved creation, including a response lost before external_id was saved.
      if (!pending || (pending.provider === 'paypal' && !pending.external_id)) {
        if (Date.parse(latest.reserved_until) <= Date.now()) return
        const key = pending?.idempotency_key ?? attempt.key
        saveSession(paymentKey(user.id, id), { ...attempt, key, provider: chosen })
        errorContext = 'start-payment'
        pending = await run(token => createPayment(token, id, key, controller.signal, chosen))
        created = true
      }
      if (controller.signal.aborted) return
      if (pending.provider !== chosen) throw new Error('Unexpected payment provider')
      if (pending.status === 'pending') {
        const choice = stored && pending.idempotency_key === stored.key ? stored.desired : desired
        saveSession(paymentKey(user.id, id), { key: pending.idempotency_key ?? attempt.key, desired: choice, provider: pending.provider, paymentId: pending.id })
        if (pending.provider === 'paypal') {
          if (!pending.external_id) throw new Error('PayPal creation is unconfirmed')
          if (created) window.location.assign(payPalApprovalUrl(pending.redirect_url))
          else navigate(`/paypal/return?token=${encodeURIComponent(pending.external_id)}`)
          return
        }
        errorContext = 'confirm'
        await run(token => simulatePayment(token, pending!.id, choice, controller.signal))
      }
      errorContext = 'load-order'
      latest = await run(token => getOrder(token, id, controller.signal))
      if (!controller.signal.aborted) { accept(latest); setUncertain(false) }
    } catch (error) {
      if (!controller.signal.aborted) { setMessage(checkoutMessage(error, errorContext)); setUncertain(true) }
    } finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }
  async function cancel() {
    if (locked.current) return
    locked.current = true; setBusy(true)
    const controller = new AbortController(); active.current = controller
    try { const next = await run(token => cancelOrder(token, id, controller.signal)); if (!controller.signal.aborted) accept(next) }
    catch (error) { if (!controller.signal.aborted) { setMessage(checkoutMessage(error, 'cancel')); setUncertain(true) } }
    finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }
  const latest = order?.payments[0]
  const saved = user ? readSession<PaymentAttempt>(paymentKey(user.id, id)) : null
  const savedPending = latest?.status === 'pending' && latest.idempotency_key === saved?.key
  return <main className={`${styles.main} ${styles.redesign}`}><h1>Mokėjimas</h1>
    {message && <p role="alert" className={styles.notice}>{message}</p>}
    {!order ? <section className={styles.panel}><p role="status">{busy ? 'Įkeliamas užsakymas…' : 'Nepavyko įkelti užsakymo.'}</p><button onClick={() => void load()} disabled={busy}>Atnaujinti užsakymą</button></section> : <div className={styles.paymentLayout}>
      <section className={`${styles.panel} ${styles.paymentPanel}`}>
        <p className={styles.mobileTotal}>Iš viso <strong>{formatCents(priceInCents(order.total_amount))} {order.currency}</strong></p>
        {order.status === 'pending_payment' ? <>
          <div className={styles.paymentMeta}>
          {remaining > 0 ? <p role="timer" aria-live="off">Rezervacija galioja dar: <strong>{Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}</strong></p>
            : <p role="status">{busy ? 'Atnaujinama užsakymo būsena…' : uncertain ? 'Pradinis rezervacijos terminas baigėsi. Nepavyko gauti dabartinės užsakymo būsenos. Prieš tęsdami atnaujinkite užsakymą.' : 'Pradinis rezervacijos terminas baigėsi. Pagal serverio duomenis užsakymas vis dar laukia mokėjimo.'}</p>}
          <p>Tai demonstracinis mokėjimas. Pinigai nenuskaičiuojami. Neveskite banko kortelės duomenų.</p>
          {latest?.status === 'failed' && <p role="alert">Mokėjimas nepavyko. Rezervacija nepratęsta. Galite bandyti dar kartą.</p>}
          {method === 'paypal' && <p>{pendingPayPal?.external_id ? 'Patvirtinę galite užbaigti mokėjimą, kuriam pritarėte „PayPal“. Tęsdami „PayPal“ atidarysite pradėtą mokėjimą bandomojoje aplinkoje.' : 'Būsite nukreipti į „PayPal Sandbox“ patvirtinti mokėjimo. Pinigai nenuskaičiuojami.'}</p>}
          </div>
          <fieldset className={styles.methods} disabled={busy || uncertain || Boolean(pendingPayment)}><legend>Mokėjimo būdas</legend>
            <label><input type="radio" name="provider" checked={method === 'fake_card'} onChange={() => setProvider('fake_card')} /> Fake Card</label>
            <label><input type="radio" name="provider" checked={method === 'paypal'} onChange={() => setProvider('paypal')} /> PayPal Sandbox</label>
          </fieldset>
          {pendingPayment && <p>Kol vyksta šis mokėjimo bandymas, mokėjimo būdo pakeisti negalima.</p>}
          {method === 'fake_card' && <fieldset className={styles.demoResult} disabled={busy || remaining <= 0 || uncertain || savedPending}><legend>Bandomojo mokėjimo rezultatas</legend>
            <label><input type="radio" name="result" checked={desired === 'success'} onChange={() => setDesired('success')} /> Sėkmingas mokėjimas</label>
            <label><input type="radio" name="result" checked={desired === 'failure'} onChange={() => setDesired('failure')} /> Nesėkmingas mokėjimas</label>
          </fieldset>}
          {savedPending && method === 'fake_card' && <p className={styles.savedDemo}>Kartojant šį mokėjimo bandymą bus naudojamas išsaugotas bandomojo mokėjimo rezultatas.</p>}
          <button className={`${styles.primary} ${styles.payButton}`} disabled={busy || (remaining <= 0 && !pendingPayPal?.external_id) || uncertain} onClick={() => void pay()}>{busy ? 'Palaukite…' : method === 'paypal' ? pendingPayPal?.external_id ? 'Patvirtinti „PayPal“ mokėjimą' : 'Mokėti per „PayPal Sandbox“' : latest?.status === 'failed' ? 'Bandyti mokėti dar kartą' : latest?.status === 'pending' ? 'Tęsti bandomąjį mokėjimą' : 'Mokėti bandomąja kortele'}</button>
          {pendingPayPal?.redirect_url && <button disabled={busy || uncertain} onClick={() => {
            try { window.location.assign(payPalApprovalUrl(pendingPayPal.redirect_url)) }
            catch (error) { setMessage(checkoutMessage(error, 'redirect')) }
          }}>Tęsti „PayPal“</button>}
          <button disabled={busy} onClick={() => void cancel()}>Atšaukti užsakymą</button>
        </> : <p role="status">{order.status === 'expired' ? 'Rezervacijos laikas baigėsi. Grįžkite į krepšelį ir patikrinkite turimą kiekį.' : order.status === 'cancelled' ? 'Šis užsakymas atšauktas.' : 'Mokėjimas patvirtintas.'}</p>}
        <button disabled={busy} onClick={() => void load()}>{uncertain ? 'Patikrinti mokėjimo būseną' : 'Atnaujinti užsakymą'}</button>
        <Link to="/cart" onClick={() => { if (user && order.status !== 'pending_payment' && readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId === id) sessionStorage.removeItem(checkoutKey(user.id)) }}>Grįžti į krepšelį</Link>
      </section>
      <div className={styles.orderSummary} data-expanded={detailsExpanded}>
        <button className={styles.detailsToggle} type="button" aria-expanded={detailsExpanded} aria-controls={`order-details-${id}`} onClick={() => setDetailsExpanded(value => !value)}>Užsakymo informacija <span aria-hidden="true">{detailsExpanded ? '−' : '+'}</span></button>
        <div className={styles.orderContent} id={`order-details-${id}`}><Summary order={order} /></div>
      </div>
    </div>}
  </main>
}
