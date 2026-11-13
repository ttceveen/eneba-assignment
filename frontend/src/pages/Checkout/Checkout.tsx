import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { CheckoutError, checkoutMessage, createOrder } from '../../api/checkout'
import { useAuth } from '../../auth/context'
import { useCart } from '../../cart/context'
import type { CartItem } from '../../cart/cart'
import { checkoutAttempt, checkoutKey, saveSession } from '../../checkout/session'
import { beginPurchase, bindPurchase } from '../../checkout/cartPurchases'
import { useConnection } from '../../checkout/useConnection'
import styles from './Checkout.module.css'

export default function Checkout() {
  const { user } = useAuth()
  const { getSnapshot } = useCart()
  const run = useConnection(), navigate = useNavigate()
  const [retry, setRetry] = useState(0)
  const [state, setState] = useState<{ message: string; failed: boolean; definitive: boolean }>({ message: 'Reserving your items...', failed: false, definitive: false })
  useEffect(() => {
    if (!user) return
    const controller = new AbortController()
    void (async () => {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const current = JSON.parse(getSnapshot())[1] as CartItem[]
        const attempt = checkoutAttempt(user.id, current)
        if (attempt.orderId) { navigate(`/orders/${attempt.orderId}`, { replace: true }); return }
        if (!attempt.items.length) { setState({ message: 'Your cart is empty.', failed: false, definitive: true }); return }
        if (!attempt.tracked) {
          beginPurchase(attempt.key, user.id, attempt.items)
          attempt.tracked = true
          saveSession(checkoutKey(user.id), attempt)
        }
        const order = await run(token => createOrder(token, attempt.items, attempt.key, controller.signal))
        if (controller.signal.aborted) return
        bindPurchase(attempt.key, user.id, order.id)
        saveSession(checkoutKey(user.id), { ...attempt, orderId: order.id })
        navigate(`/orders/${order.id}`, { replace: true })
      } catch (error) {
        if (!controller.signal.aborted) setState({ message: checkoutMessage(error), failed: true, definitive: error instanceof CheckoutError && [409, 422].includes(error.status) })
      }
    })()
    return () => controller.abort()
  }, [user, getSnapshot, run, navigate, retry])
  return <main className={styles.main}><h1>Checkout</h1><section className={styles.panel}>
    <p role={state.failed ? 'alert' : 'status'}>{state.message}</p>
    {state.failed && <button className={styles.primary} onClick={() => { setState({ message: 'Checking the same checkout request...', failed: false, definitive: false }); setRetry(v => v + 1) }}>Retry checkout</button>}
    <Link to="/cart" onClick={() => { if (state.definitive && user) sessionStorage.removeItem(checkoutKey(user.id)) }}>Back to cart</Link>
  </section></main>
}
