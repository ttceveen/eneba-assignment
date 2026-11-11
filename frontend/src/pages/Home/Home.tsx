import { useEffect, useState } from 'react'
import { getGames, type Game } from '../../api/games'
import GameCard from '../../components/GameCard/GameCard'
import styles from './Home.module.css'

type CatalogState =
  | { status: 'loading' }
  | { status: 'success'; games: Game[] }
  | { status: 'error' }

export default function Home() {
  const [catalog, setCatalog] = useState<CatalogState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    getGames(controller.signal)
      .then((games) => {
        if (!controller.signal.aborted) setCatalog({ status: 'success', games })
      })
      .catch(() => {
        if (!controller.signal.aborted) setCatalog({ status: 'error' })
      })

    return () => controller.abort()
  }, [])

  return (
    <>
      <main className={styles.main} id="games">
        <h1 className={styles.heading}>Games</h1>
        {catalog.status === 'loading' && <p role="status">Loading games...</p>}
        {catalog.status === 'error' && <p role="alert">Failed to load games.</p>}
        {catalog.status === 'success' && (
          catalog.games.length === 0 ? <p role="status">No games available.</p> : (
            <div className={styles.grid}>
              {catalog.games.map((game) => (
                <GameCard
                  key={game.id}
                  id={game.id}
                  name={game.name}
                  location={game.location}
                  img={game.img}
                />
              ))}
            </div>
          )
        )}
      </main>
    </>
  )
}
