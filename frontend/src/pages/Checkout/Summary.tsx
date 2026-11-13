import type { Order } from '../../api/checkout'
import { formatCents, priceInCents } from '../../cart/cart'
import styles from './Checkout.module.css'

export default function Summary({ order }: { order: Order }) {
  return <section aria-label="Order details" className={styles.panel}>
    <h2>Order #{order.id}</h2>
    <ul className={styles.items}>{order.items.map(item => <li key={item.id}>
      <div><strong>{item.game.name}</strong><p>Seller: {item.seller.name}</p><p>Quantity: {item.quantity} · Unit price: {formatCents(priceInCents(item.unit_price))}</p></div>
      <strong>{formatCents(priceInCents(item.unit_price) * BigInt(item.quantity))}</strong>
    </li>)}</ul>
    <p className={styles.total}>Total confirmed by server <strong>{formatCents(priceInCents(order.total_amount))} {order.currency}</strong></p>
    <p>Review these server-confirmed prices before paying; they may differ from your cart. Prices and quantities are fixed for this order.</p>
  </section>
}
