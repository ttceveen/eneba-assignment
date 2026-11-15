import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { getGame, type Game } from '../../api/games'
import GameCover from '../../components/GameCover/GameCover'
import { useCart } from '../../cart/context'
import AddToCartButton from '../../components/AddToCartButton/AddToCartButton'
import { catalogReturn } from '../../utils/catalogHistory'
import styles from './GameDetails.module.css'

type GameState =
  | { status: 'loading' }
  | { status: 'success'; game: Game }
  | { status: 'not-found' }
  | { status: 'error' }

function GameContent({ id }: { id: string }) {
  const { rememberGame, getSnapshot, stockChanges } = useCart()
  const location = useLocation(), navigate = useNavigate()
  const source = (location.state as { catalogReturn?: unknown } | null)?.catalogReturn
  const previous = source ? catalogReturn(location, true) : null
  const [retry, setRetry] = useState(0)
  const [state, setState] = useState<GameState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    const snapshot = getSnapshot()
    void (async () => {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const game = await getGame(id, controller.signal)
        if (!controller.signal.aborted) {
          if (game) rememberGame(game, snapshot)
          setState(game ? { status: 'success', game } : { status: 'not-found' })
        }
      } catch {
        if (!controller.signal.aborted) setState({ status: 'error' })
      }
    })()

    return () => controller.abort()
  }, [id, rememberGame, getSnapshot, retry])

  return (
    <main className={styles.main}>
      <Link className={styles.back} to={previous?.url ?? '/'} state={{ restoreCatalogKey: previous?.key }} onClick={event => {
        const index = history.state?.idx
        if (!event.defaultPrevented && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && previous?.index !== null && previous?.index !== undefined && Number.isInteger(index) && previous.index < index) {
          event.preventDefault()
          navigate(previous.index - index)
        }
      }}>← Games</Link>
      {state.status === 'loading' && <p role="status">Loading game...</p>}
      {state.status === 'not-found' && <p role="alert">Game not found.</p>}
      {state.status === 'error' && <p role="alert">Failed to load game.<button className={styles.retry} type="button" onClick={event => {
        event.currentTarget.disabled = true
        setState({ status: 'loading' })
        setRetry(value => value + 1)
      }}>Retry</button></p>}
      {state.status === 'success' && (
        <div className={styles.layout}>
          <div className={styles.cover}>
            <GameCover name={state.game.name} img={state.game.img} />
          </div>
          <section className={styles.information}>
            <h1>{state.game.name}</h1>
            <div className={styles.availability}>
              <h2>Availability</h2>
              <p>{state.game.location.includes('GLOBAL') ? 'Worldwide' : state.game.location.join(', ')}</p>
            </div>
            <section className={styles.offers} aria-label="Seller offers">
              <h2>Seller offers</h2>
              {state.game.offers?.length ? (
                <ul className={styles.offerList}>
                  {state.game.offers.map((offer) => (
                    <li key={offer.id} className={styles.offer}>
                      <span className={styles.seller}>{offer.seller.name}</span>
                      <span className={styles.offerPrice}>{new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(Number(offer.price))}</span>
                      <span className={styles.quantity}>Available: {offer.quantity}</span>
                      {stockChanges.filter(change => change.offerId === offer.id).map(change => <p key={change.offerId} role="status" className={styles.stockNotice}>Available stock changed. Quantity updated from {change.from} to {change.to}.</p>)}
                      <AddToCartButton offer={offer} />
                    </li>
                  ))}
                </ul>
              ) : <p className={styles.noOffers}>No offers available</p>}
            </section>
          </section>
        </div>
      )}
    </main>
  )
}

export default function GameDetails() {
  const { id = '' } = useParams<{ id: string }>()

  return <GameContent key={id} id={id} />
}
