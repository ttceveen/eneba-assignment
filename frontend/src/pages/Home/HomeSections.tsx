import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { getRecentGames, getGames, type Game } from '../../api/games'
import { useCatalogScroll } from '../../hooks/useCatalogScroll'
import { getRecentlyViewedIds } from '../../utils/recentGames'
import GameCarousel from './GameCarousel'
import styles from './Home.module.css'

const sections = [
  { sort: 'newest', title: 'Naujienos', href: '/?sort=newest' },
  { sort: 'popular', title: 'Populiariausi žaidimai', href: '/?sort=popular&popular_only=1' },
] as const

type GameShelfProps = {
  section: 'recent' | 'newest' | 'popular'
  title: string
  href: string
  games: Game[] | null | undefined
  onRetry?: () => void
}

function GameShelf({ section, title, href, games, onRetry }: GameShelfProps) {
  return (
    <section className={section === 'popular' ? `${styles.featuredSection} ${styles.popularSection}` : styles.featuredSection}
      aria-labelledby={`${section}-games`} data-catalog-section={section} aria-busy={games === undefined}>
      <div className={styles.featuredContent}>
        <h2 id={`${section}-games`} className={styles.featuredHeading}>{title}</h2>
        {games === undefined ? <p role="status">Įkeliami žaidimai…</p> : games === null ? (
          <p role="alert">Nepavyko įkelti žaidimų.
            <button className={styles.retry} type="button" onClick={onRetry}>Bandyti dar kartą</button>
          </p>
        ) : games.length === 0 ? <p role="status">Nėra pasiekiamų žaidimų.</p> : <GameCarousel games={games} />}
      </div>
      <Link className={styles.showAll} to={href}>Rodyti visus</Link>
    </section>
  )
}

export default function HomeSections() {
  const [gamesBySection, setGamesBySection] = useState<(Game[] | null)[] | null>(null)
  const [recent, setRecent] = useState<Game[] | null>(null)
  const [retry, setRetry] = useState(0)
  const { mainRef, restoring, mainHeight } = useCatalogScroll(gamesBySection !== null && recent !== null)

  useEffect(() => {
    const controller = new AbortController()

    async function load() {
      // Let Strict Mode clean up its first effect before sending requests.
      await Promise.resolve()
      if (controller.signal.aborted) return
      const results = await Promise.allSettled(sections.map(({ sort }) => getGames(1, sort, controller.signal, sort === 'popular' ? new URLSearchParams({ popular_only: '1' }) : undefined)))
      if (!controller.signal.aborted) {
        setGamesBySection(results.map(result => result.status === 'fulfilled' ? result.value.data : null))
      }
    }

    void load()
    return () => controller.abort()
  }, [retry])

  useEffect(() => {
    const controller = new AbortController()

    async function load() {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const page = await getRecentGames(getRecentlyViewedIds(), controller.signal)
        if (!controller.signal.aborted) setRecent(page.data)
      } catch {
        if (!controller.signal.aborted) setRecent([])
      }
    }

    void load()
    return () => controller.abort()
  }, [])

  function retrySections() {
    setGamesBySection(null)
    setRetry(value => value + 1)
  }

  return (
    <main className={styles.featured} id="games" ref={mainRef} aria-label="Žaidimai"
      style={{ minHeight: restoring ? mainHeight : undefined }}>
      {recent && recent.length > 0 && <GameShelf section="recent" title="Neseniai peržiūrėta" href="/?view=recent" games={recent} />}
      {sections.map(({ sort, title, href }, index) => (
        <GameShelf key={sort} section={sort} title={title} href={href} games={gamesBySection?.[index]} onRetry={retrySections} />
      ))}
    </main>
  )
}
