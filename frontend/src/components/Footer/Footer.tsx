import { Link } from 'react-router'
import styles from './Footer.module.css'

export default function Footer() {
  return (
    <footer className={styles.footer}>
      <div className={styles.inner}>
        <img className={styles.logo} src="/images/eneba-logo.svg" alt="Eneba" width="155" height="49" />
        <nav className={styles.links} aria-label="Poraštės navigacija">
          <div>
            <h2>Katalogas</h2>
            <Link to="/">Žaidimai</Link>
            <Link to="/?sort=alphabetical">Visi žaidimai</Link>
          </div>
          <div>
            <h2>Paskyra</h2>
            <Link to="/cart">Krepšelis</Link>
            <Link to="/orders">Užsakymai</Link>
          </div>
        </nav>
        <p className={styles.credit}>„Eneba“ prekyvietė – praktikos užduotis</p>
      </div>
    </footer>
  )
}
