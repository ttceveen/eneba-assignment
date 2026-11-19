import { useEffect, useId, useRef, useState } from 'react'
import { useAuth } from '../../auth/context'
import { Link, useLocation } from 'react-router'
import styles from './Header.module.css'
import { useCart } from '../../cart/context'
import { cartCount } from '../../cart/cart'
import SearchPanel from '../SearchPanel/SearchPanel'
import { catalogPlatforms } from '../../api/games'

const categories = catalogPlatforms.map(platform => ({
  platform,
  to: { pathname: '/', search: new URLSearchParams({ platform }).toString() },
}))

export default function Header() {
  const { user, loading, signOut } = useAuth()
  const [logoutError, setLogoutError] = useState('')
  const [loggingOut, setLoggingOut] = useState(false)
  async function handleLogout() {
    setLoggingOut(true); setLogoutError('')
    try { await signOut() } catch { setLogoutError('Nepavyko atsijungti. Bandykite dar kartą.') }
    finally { setLoggingOut(false) }
  }
  const { items } = useCart()
  const count = cartCount(items)
  const location = useLocation()
  const { key, pathname, search } = location
  const [categoriesLocation, setCategoriesLocation] = useState<typeof location | null>(null)
  const categoriesOpen = categoriesLocation === location
  const categoriesId = useId()
  const panel = useRef<HTMLDivElement>(null)
  const opener = useRef<HTMLButtonElement | null>(null)
  const activePlatform = pathname === '/' ? new URLSearchParams(search).get('platform') : null
  const allGames = pathname === '/' && !search

  useEffect(() => {
    if (!categoriesOpen) return
    if (opener.current?.classList.contains(styles.mobileCategories)) panel.current?.querySelector('a')?.focus()

    const closeOutside = (event: Event) => {
      const target = event.target
      if (target instanceof Node && !opener.current?.contains(target) && !panel.current?.contains(target)) {
        setCategoriesLocation(null)
      }
    }

    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape' || event.defaultPrevented) return
      event.preventDefault()
      setCategoriesLocation(null)
      opener.current?.focus()
    }

    const layout = window.matchMedia('(min-width: 900px)')
    const closeOnLayoutChange = () => setCategoriesLocation(null)
    document.addEventListener('pointerdown', closeOutside)
    document.addEventListener('focusin', closeOutside)
    document.addEventListener('keydown', closeOnEscape)
    layout.addEventListener('change', closeOnLayoutChange)
    return () => {
      document.removeEventListener('pointerdown', closeOutside)
      document.removeEventListener('focusin', closeOutside)
      document.removeEventListener('keydown', closeOnEscape)
      layout.removeEventListener('change', closeOnLayoutChange)
    }
  }, [categoriesOpen])

  function categoryTrigger(mobile: boolean) {
    return (
      <button type="button" className={mobile ? styles.mobileCategories : styles.categoriesButton}
        aria-label={mobile ? 'Kategorijos' : undefined} aria-expanded={categoriesOpen} aria-controls={categoriesId}
        onClick={event => {
          opener.current = event.currentTarget
          setCategoriesLocation(current => current === location ? null : location)
        }}>
        <span className={styles.menuIcon} aria-hidden="true" />
        {!mobile && 'Kategorijos'}
      </button>
    )
  }

  function platformLinks() {
    return categories.map(({ platform, to }) => (
      <Link key={platform} to={to} aria-current={activePlatform === platform ? 'page' : undefined}
        onClick={() => setCategoriesLocation(null)}>{platform}</Link>
    ))
  }
  const accountIcon = (
    <svg className={styles.accountIcon} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" aria-hidden="true">
      <circle cx="12" cy="7" r="4" /><path d="M4 22v-2a8 8 0 0 1 16 0v2" />
    </svg>
  )
  return (
    <header className={styles.header}>
      <div className={styles.inner}>
        {categoryTrigger(true)}
        <Link className={styles.logo} to="/" aria-label="Eneba pradžia">
          <img src="/images/eneba-logo.svg" alt="Eneba" width="155" height="49" />
        </Link>
        <SearchPanel key={key} />
        <div className={styles.actions}>
          <span className={styles.currency}>EUR</span>
          <Link className={styles.cart} to="/cart" aria-label={`Pirkinių krepšelis. Prekių: ${count}`}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M2 3h3l2.2 12h12L22 6H5.6M8 15l-1 3h13" />
              <circle cx="9" cy="21" r="1" /><circle cx="19" cy="21" r="1" />
            </svg>
            <span className={styles.cartCount} aria-hidden="true">{count > 99 ? '99+' : count}</span>
          </Link>
          {user ? <>
            <Link className={styles.account} to="/orders" aria-label="Užsakymų istorija">{accountIcon}<span className={styles.accountName}>{user.first_name}</span><span className={styles.accountLabel}>Paskyra</span></Link>
            <button className={styles.account} type="button" disabled={loggingOut} onClick={handleLogout}>{loggingOut ? 'Atsijungiama…' : 'Atsijungti'}</button>
          </> : <>
            <Link className={styles.account} to="/login" aria-label="Prisijungti">{accountIcon}<span className={styles.accountLabel}>{loading ? 'Tikrinama…' : 'Prisijungti'}</span></Link>
            {!loading && <Link className={styles.register} to="/register">Registruotis</Link>}
          </>}
          {logoutError && <span role="alert">{logoutError}</span>}
        </div>
      </div>
      <nav className={styles.navigation} aria-label="Katalogo navigacija">
        <div className={styles.navigationInner}>
          {categoryTrigger(false)}
          <div className={styles.categoriesPanel} id={categoriesId} ref={panel} hidden={!categoriesOpen}>
            {platformLinks()}
          </div>
          <div className={styles.navigationLinks}>
            <Link to="/" aria-current={allGames ? 'page' : undefined}>Žaidimai</Link>
            {platformLinks()}
          </div>
        </div>
      </nav>
    </header>
  )
}
