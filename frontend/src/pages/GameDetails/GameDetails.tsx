import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { getGame, type Game } from '../../api/games'
import GameCover from '../../components/GameCover/GameCover'
import styles from './GameDetails.module.css'

type GameState =
  | { status: 'loading' }
  | { status: 'success'; game: Game }
  | { status: 'not-found' }
  | { status: 'error' }

function GameContent({ id }: { id: string }) {
  const [state, setState] = useState<GameState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    getGame(id, controller.signal)
      .then((game) => {
        if (!controller.signal.aborted) {
          setState(game ? { status: 'success', game } : { status: 'not-found' })
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setState({ status: 'error' })
      })

    return () => controller.abort()
  }, [id])

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
