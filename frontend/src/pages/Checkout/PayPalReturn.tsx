import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { checkoutMessage, confirmPayPalPayment, isPayPalOrderId, payPalApprovalUrl, type Payment } from '../../api/checkout'
import { useAuth } from '../../auth/context'
import { checkoutKey, readSession, type CheckoutAttempt } from '../../checkout/session'
import { useConnection } from '../../checkout/useConnection'
import styles from './Checkout.module.css'

export default function PayPalReturn() {
  const [params] = useSearchParams(), navigate = useNavigate(), { user } = useAuth()
  const userId = user?.id
  const tokens = params.getAll('token'), paypalOrderId = tokens.length === 1 ? tokens[0] : null
  const valid = isPayPalOrderId(paypalOrderId), cancelled = params.get('cancelled') === '1'
  const run = useConnection(), active = useRef<AbortController | null>(null), locked = useRef(false)
  const [returnedPayment, setReturnedPayment] = useState<Payment | null>(null)
  const [attempted, setAttempted] = useState(false), [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState(false), [message, setMessage] = useState('Tvirtinamas „PayPal“ mokėjimas…')
  const savedOrder = user ? readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId : undefined
  const orderId = returnedPayment && returnedPayment.external_id === paypalOrderId ? returnedPayment.order_id : savedOrder
  const back = Number.isSafeInteger(orderId) && orderId! > 0 ? `/orders/${orderId}` : '/checkout'
  const check = useCallback(async () => {
    if (!isPayPalOrderId(paypalOrderId) || !userId || locked.current) return
    locked.current = true; setAttempted(true); setFailed(false); setBusy(true); setMessage('Tvirtinamas „PayPal“ mokėjimas…')
    const controller = new AbortController(); active.current = controller
    try {
      const payment = await run(token => confirmPayPalPayment(paypalOrderId, token, controller.signal))
      if (controller.signal.aborted) return
      setReturnedPayment(payment)
      setFailed(payment.status === 'failed' || payment.status === 'cancelled')
      if (payment.status === 'succeeded') { navigate(`/orders/${payment.order_id}/success`, { replace: true }); return }
      setMessage(payment.status === 'pending'
        ? '„PayPal“ dar nepatvirtino mokėjimo. Galite tęsti „PayPal“ aplinkoje arba bandyti patvirtinti dar kartą.'
        : 'Mokėjimas nepatvirtintas. Jūsų krepšelis išsaugotas.')
    } catch (error) { if (!controller.signal.aborted) { setFailed(true); setMessage(checkoutMessage(error, 'confirm')) } }
    finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }, [paypalOrderId, userId, run, navigate])
  useEffect(() => {
    let disposed = false
    // StrictMode's discarded effect must not start a confirmation request.
    void Promise.resolve().then(() => { if (!disposed && valid && !cancelled) void check() })
    return () => { disposed = true; active.current?.abort(); locked.current = false }
  }, [check, valid, cancelled])
  return <main className={styles.main}><h1>{cancelled ? '„PayPal“ mokėjimas nutrauktas' : '„PayPal“ mokėjimas'}</h1><section className={styles.panel}>
    {cancelled && <p>Grįžote iš „PayPal“ neužbaigę mokėjimo. Kol kas neaišku, ar mokėjimas įvykdytas. Užsakymas ir krepšelis išsaugoti.</p>}
    {!valid && !cancelled && <p role="alert">Grįžimo iš „PayPal“ nuorodoje nėra galiojančio užsakymo identifikatoriaus. Norėdami tęsti, grįžkite prie užsakymo.</p>}
    {valid && (!cancelled || attempted) && <p role={failed ? "alert" : "status"}>{message}</p>}
    {valid && <p>Patvirtinę galite užbaigti mokėjimą, kuriam pritarėte „PayPal“. Bandomojoje aplinkoje pinigai nenuskaičiuojami.</p>}
    {valid && <button className={styles.primary} disabled={busy} onClick={() => void check()}>{busy ? 'Tvirtinamas „PayPal“ mokėjimas…' : 'Patvirtinti „PayPal“ mokėjimą'}</button>}
    {returnedPayment?.status === 'pending' && returnedPayment.external_id === paypalOrderId && returnedPayment.redirect_url && <button disabled={busy} onClick={() => {
      try { window.location.assign(payPalApprovalUrl(returnedPayment.redirect_url)) }
      catch (error) { setFailed(true); setMessage(checkoutMessage(error, 'redirect')) }
    }}>Tęsti „PayPal“</button>}
    <Link to={back}>Grįžti prie užsakymo</Link><Link to="/cart">Grįžti į krepšelį</Link>
  </section></main>
}
