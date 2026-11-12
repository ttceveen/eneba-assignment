import { useEffect, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router'
import { getGames, searchGames, type GamesPage, type CatalogSort } from '../../api/games'
import { useCatalogScroll } from '../../hooks/useCatalogScroll'
import { rememberCatalog } from '../../utils/catalogHistory'
import GameCard from '../../components/GameCard/GameCard'
import styles from './Home.module.css'

type CatalogState =
  | { status: 'loading' }
  | { status: 'success'; page: GamesPage }
  | { status: 'error' }

function CatalogPage({ page, sort, query, onPageChange }: { page: number; sort: CatalogSort; query: string; onPageChange: (page: number) => void }) {
  const [catalog, setCatalog] = useState<CatalogState>({ status: 'loading' })
  const loaded = catalog.status === 'success'
  const { mainRef, remember, restoring: pendingRestoration, mainHeight } = useCatalogScroll(loaded)
  const restoring = pendingRestoration && catalog.status !== 'error'

  function navigatePage(number: number) {
    if (number === page || catalog.status !== 'success' || number < 1 || number > catalog.page.meta.last_page) return
    remember()
    onPageChange(number)
  }

  useEffect(() => {
    const controller = new AbortController()
    const request = query ? searchGames(page, query, controller.signal) : getGames(page, sort, controller.signal)
    request
      .then((result) => {
        if (!controller.signal.aborted) setCatalog({ status: 'success', page: result })
      })
      .catch(() => {
        if (!controller.signal.aborted) setCatalog({ status: 'error' })
      })

    return () => controller.abort()
  }, [page, sort, query])

  return (
    <main className={styles.main} id="games" ref={mainRef}
      aria-busy={catalog.status === 'loading' || (loaded && restoring)}
      style={{ minHeight: restoring ? mainHeight : undefined }}>
      <h1 className={styles.heading}>{query ? `Search results for “${query}”` : 'Games'}</h1>
      {catalog.status === 'loading' && <p role="status" className={restoring ? styles.restoringStatus : undefined}>Loading games...</p>}
      <div style={{ visibility: restoring && loaded ? 'hidden' : undefined }}>
        {catalog.status === 'error' && <p role="alert">Failed to load games.</p>}
        {catalog.status === 'success' && (
          <>
            {catalog.page.data.length === 0 ? <p role="status">{query ? 'No games found.' : 'No games available.'}</p> : (
              <div className={styles.grid}>
                {catalog.page.data.map((game) => (
                  <GameCard key={game.id} id={game.id} name={game.name} location={game.location} img={game.img} price={game.min_price} offersCount={game.offers_count} />
                ))}
              </div>
            )}
            {catalog.page.meta.last_page > 1 && (
              <nav className={styles.pagination} aria-label="Catalog pagination">
                <button type="button" aria-label="Previous page" disabled={catalog.page.meta.current_page <= 1}
                  onClick={() => navigatePage(Math.min(catalog.page.meta.current_page - 1, catalog.page.meta.last_page))}>←</button>
                {Array.from({ length: catalog.page.meta.last_page }, (_, index) => index + 1).map((number) => (
                  <button key={number} type="button" aria-label={`Page ${number}`}
                    aria-current={number === catalog.page.meta.current_page ? 'page' : undefined}
                    onClick={() => navigatePage(number)}>{number}</button>
                ))}
                <button type="button" aria-label="Next page" disabled={catalog.page.meta.current_page >= catalog.page.meta.last_page}
                  onClick={() => navigatePage(catalog.page.meta.current_page + 1)}>→</button>
              </nav>
            )}
          </>
        )}
      </div>
    </main>
  )
}

export default function Home() {
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  const query = searchParams.get('q')?.trim() ?? ''
  useEffect(() => { if (!query) rememberCatalog(location) }, [location, query])
  const sort: CatalogSort = searchParams.get('sort') === 'alphabetical' ? 'alphabetical' : 'popular'
  const rawPage = searchParams.get('page') ?? '1'
  const parsedPage = Number(rawPage)
  const page = /^[1-9]\d*$/.test(rawPage) && Number.isSafeInteger(parsedPage) ? parsedPage : 1

  function changePage(nextPage: number) {
    if (nextPage === page || nextPage < 1) return
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      if (nextPage === 1) next.delete('page')
      else next.set('page', String(nextPage))
      return next
    }, { state: location.state })
  }

  return <CatalogPage key={`${location.key}:${sort}:${page}:${query}`} page={page} sort={sort} query={query} onPageChange={changePage} />
}
