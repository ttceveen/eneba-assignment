import { useLayoutEffect, useRef } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { getGame, readPlatformContext } from '../../api/games'
import { catalogReturn } from '../../utils/catalogHistory'
import AddToCartButton from '../AddToCartButton/AddToCartButton'
import GameCover from '../GameCover/GameCover'
import styles from './GameCard.module.css'

type GameCardProps = {
  id: number
  name: string
  location: string[]
  img?: string | null
  price?: string | number | null
  offersCount?: number
  compact?: boolean
}

export default function GameCard({ id, name, location, img, price, offersCount = 0, compact = false }: GameCardProps) {
  const routerLocation = useLocation()
  const navigate = useNavigate()
  const platform = compact ? readPlatformContext(routerLocation.search) : undefined
  const detailPath = `/games/${id}${platform ? `?${new URLSearchParams({ platform })}` : ''}`
  const detailState = { catalogReturn: catalogReturn(routerLocation, true) }
  const panelRef = useRef<HTMLDivElement>(null)
  const actionsRef = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const panel = panelRef.current
    const actions = actionsRef.current
    if (!panel || !actions) return

    const measure = () => panel.style.setProperty('--actions-height', `${actions.getBoundingClientRect().height}px`)
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(actions)
    return () => observer.disconnect()
  }, [])

  async function resolveOffer(signal: AbortSignal) {
    const game = await getGame(String(id), signal)
    if (signal.aborted) return null
    if (game?.id === id && game.offers?.length === 1) return game.offers[0]

    navigate(detailPath, { state: detailState })
    return null
  }
  const region = location.includes('GLOBAL') ? 'Visame pasaulyje' : location.join(' · ')
  return (
    <article className={compact ? `${styles.card} ${styles.compact}` : styles.card} data-game-id={id}>
      <Link className={styles.coverLink} to={detailPath} state={detailState} tabIndex={-1} aria-hidden="true">
        <GameCover name={name} img={img} />
      </Link>
      <div className={styles.panel} ref={panelRef}>
        <Link className={styles.link} to={detailPath} state={detailState} aria-label={name}>
          <div className={styles.details}>
            <h2 className={styles.name}>{name}</h2>
            <p className={styles.region} aria-label={`Prieinamumas: ${region}`}>
              {region}
            </p>
            <div className={styles.offers}>
              {offersCount > 0 && price !== undefined && price !== null ? (
                <>
                  <p className={styles.price}><span>Nuo</span>{new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(Number(price))}</p>
                  <p className={styles.offerCount}>Pasiūlymų: {offersCount}</p>
                </>
              ) : <p className={styles.noOffers}>Pasiūlymų nėra</p>}
            </div>
          </div>
        </Link>
        <div className={styles.actions} ref={actionsRef}>
          {offersCount === 1 ? <AddToCartButton resolveOffer={resolveOffer} /> : (
            <Link className={styles.primary} to={detailPath} state={detailState}>Į krepšelį</Link>
          )}
          <Link className={styles.secondary} to={detailPath} state={detailState}>Peržiūrėti pasiūlymus</Link>
        </div>
      </div>
    </article>
  )
}
