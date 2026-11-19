import { Link } from 'react-router'
import styles from './Auth.module.css'
export default function ProtectedPage({ title }: { title: string }) {
  return <main className={styles.main}><section className={styles.panel}><h1>{title}</h1><p>Ši funkcija dar nepasiekiama.</p><Link to="/cart">Grįžti į krepšelį</Link></section></main>
}
