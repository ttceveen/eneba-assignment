import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { checkoutMessage, confirmPayPalPayment, isPayPalOrderId } from '../../api/checkout'
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
  const [attempted, setAttempted] = useState(false)
  const [busy, setBusy] = useState(false), [message, setMessage] = useState('Checking PayPal payment...')
  const savedOrder = user ? readSession<CheckoutAttempt>(checkoutKey(user.id))?.orderId : undefined
  const back = Number.isSafeInteger(savedOrder) && savedOrder! > 0 ? `/orders/${savedOrder}` : '/checkout'
  const check = useCallback(async () => {
    if (!isPayPalOrderId(paypalOrderId) || !userId || locked.current) return
    locked.current = true; setAttempted(true); setBusy(true); setMessage('Checking PayPal payment...')
    const controller = new AbortController(); active.current = controller
    try {
      const payment = await run(token => confirmPayPalPayment(paypalOrderId, token, controller.signal))
      if (controller.signal.aborted) return
      if (payment.status === 'succeeded') { navigate(`/orders/${payment.order_id}/success`, { replace: true }); return }
      setMessage(payment.status === 'pending'
        ? 'Платёж ещё обрабатывается. Вы можете повторить проверку.'
        : 'Payment has not been confirmed. Your cart has been kept.')
    } catch (error) { if (!controller.signal.aborted) setMessage(checkoutMessage(error)) }
    finally { if (!controller.signal.aborted) { locked.current = false; setBusy(false) } }
  }, [paypalOrderId, userId, run, navigate])
  useEffect(() => {
    let disposed = false
    // StrictMode's discarded effect must not start a confirmation request.
    void Promise.resolve().then(() => { if (!disposed && valid && !cancelled) void check() })
    return () => { disposed = true; active.current?.abort(); locked.current = false }
  }, [check, valid, cancelled])
  return <main className={styles.main}><h1>{cancelled ? 'PayPal checkout interrupted' : 'PayPal payment'}</h1><section className={styles.panel}>
    {cancelled && <p>You returned from PayPal without completing this checkout. This does not confirm whether a payment was captured. Your order and cart have been kept.</p>}
    {!valid && !cancelled && <p role="alert">The PayPal return link is missing a valid Order ID. Return to checkout to continue.</p>}
    {valid && (!cancelled || attempted) && <p role="status">{message}</p>}
    {valid && <button className={styles.primary} disabled={busy} onClick={() => void check()}>{busy ? 'Checking payment...' : 'Repeat payment check'}</button>}
    <Link to={back}>Back to checkout</Link><Link to="/cart">Back to cart</Link>
  </section></main>
}
