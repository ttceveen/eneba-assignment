import { Link } from 'react-router'
import styles from './Auth.module.css'
export default function ProtectedPage({ title }: { title: string }) {
  return <main className={styles.main}><section className={styles.panel}><h1>{title}</h1><p>This feature is not available yet.</p><Link to="/cart">Back to cart</Link></section></main>
}
