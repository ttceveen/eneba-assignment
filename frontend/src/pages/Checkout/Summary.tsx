import type { Order } from '../../api/checkout'
import { formatCents, priceInCents } from '../../cart/cart'
import styles from './Checkout.module.css'

export default function Summary({ order }: { order: Order }) {
  return <section aria-label="Užsakymo informacija" className={styles.panel}>
    <h2>Užsakymas Nr. {order.id}</h2>
    <ul className={styles.items}>{order.items.map(item => <li key={item.id}>
      <div><strong>{item.game.name}</strong><p>Pardavėjas: {item.seller.name}</p><p>Kiekis: {item.quantity} · Vieneto kaina: {formatCents(priceInCents(item.unit_price))}</p></div>
      <strong>{formatCents(priceInCents(item.unit_price) * BigInt(item.quantity))}</strong>
    </li>)}</ul>
    <p className={styles.total}>Patvirtinta bendra suma <strong>{formatCents(priceInCents(order.total_amount))} {order.currency}</strong></p>
    <p>Prieš mokėdami peržiūrėkite patvirtintas kainas: jos gali skirtis nuo kainų krepšelyje. Šio užsakymo kainos ir kiekiai nebesikeis.</p>
  </section>
}
