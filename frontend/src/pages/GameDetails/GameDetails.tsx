import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { getGame, type Game } from '../../api/games'
import GameCover from '../../components/GameCover/GameCover'
import { useCart } from '../../cart/context'
import AddToCartButton from '../../components/AddToCartButton/AddToCartButton'
import styles from './GameDetails.module.css'

type GameState =
  | { status: 'loading' }
  | { status: 'success'; game: Game }
  | { status: 'not-found' }
  | { status: 'error' }

function GameContent({ id }: { id: string }) {
  const { rememberGame, getSnapshot } = useCart()
  const [state, setState] = useState<GameState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    const snapshot = getSnapshot()
    getGame(id, controller.signal)
      .then((game) => {
        if (!controller.signal.aborted) {
          if (game) rememberGame(game, snapshot)
          setState(game ? { status: 'success', game } : { status: 'not-found' })
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setState({ status: 'error' })
      })

    return () => controller.abort()
  }, [id, rememberGame, getSnapshot])

  return (
    <main className={styles.main}>
      <Link className={styles.back} to="/">← Games</Link>
      {state.status === 'loading' && <p role="status">Loading game...</p>}
      {state.status === 'not-found' && <p role="alert">Game not found.</p>}
      {state.status === 'error' && <p role="alert">Failed to load game.</p>}
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
            <section className={styles.offers} aria-label="Предложения продавцов">
              <h2>Предложения продавцов</h2>
              {state.game.offers?.length ? (
                <ul className={styles.offerList}>
                  {state.game.offers.map((offer) => (
                    <li key={offer.id} className={styles.offer}>
                      <span className={styles.seller}>{offer.seller.name}</span>
                      <span className={styles.offerPrice}>{new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(Number(offer.price))}</span>
                      <span className={styles.quantity}>Доступно: {offer.quantity}</span>
                      <AddToCartButton offer={offer} />
                    </li>
                  ))}
                </ul>
              ) : <p className={styles.noOffers}>Нет предложений</p>}
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
