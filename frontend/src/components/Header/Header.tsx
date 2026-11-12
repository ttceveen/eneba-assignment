import { Link, useLocation } from 'react-router'
import styles from './Header.module.css'
import { useCart } from '../../cart/context'
import { cartCount } from '../../cart/cart'
import SearchPanel from '../SearchPanel/SearchPanel'

export default function Header() {
  const { items } = useCart()
  const count = cartCount(items)
  const { pathname, search, key } = useLocation()
  const searching = Boolean(new URLSearchParams(search).get('q')?.trim())
  const alphabetical = new URLSearchParams(search).get('sort') === 'alphabetical'
  return (
    <header className={styles.header}>
      <div className={styles.inner}>
        <Link className={styles.logo} to="/" aria-label="Eneba home">
          <img src="/images/eneba-logo.svg" alt="Eneba" width="155" height="49" />
        </Link>
        <SearchPanel key={key} />
        <div className={styles.actions}>
          <button className={styles.account} type="button" aria-label="Log in" disabled title="Sign in is not available yet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true">
              <circle cx="12" cy="7" r="3.5" /><path d="M4.5 21v-2.5a7.5 7.5 0 0 1 15 0V21" />
            </svg>
            <span>Log in</span>
          </button>
          <Link className={styles.cart} to="/cart" aria-label={`Shopping cart, ${count} ${count === 1 ? 'item' : 'items'}`}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M2 3h3l2.2 12h12L22 6H5.6M8 15l-1 3h13" />
              <circle cx="9" cy="21" r="1" /><circle cx="19" cy="21" r="1" />
            </svg>
            <span className={styles.cartCount} aria-hidden="true">{count > 99 ? '99+' : count}</span>
          </Link>
        </div>
      </div>
      <nav className={styles.navigation} aria-label="Main navigation">
        <div className={styles.navInner}>
          <Link to="/" aria-current={pathname === '/' && !searching && !alphabetical ? 'page' : undefined}>Games</Link>
          <Link to="/?sort=alphabetical" aria-current={pathname === '/' && !searching && alphabetical ? 'page' : undefined}>All Games</Link>
        </div>
      </nav>
    </header>
  )
}
