import { useEffect, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router'
import { getGames, type GamesPage, type CatalogSort } from '../../api/games'
import { useCatalogScroll } from '../../hooks/useCatalogScroll'
import GameCard from '../../components/GameCard/GameCard'
import styles from './Home.module.css'

type CatalogState =
  | { status: 'loading' }
  | { status: 'success'; page: GamesPage }
  | { status: 'error' }

function CatalogPage({ page, sort, onPageChange }: { page: number; sort: CatalogSort; onPageChange: (page: number) => void }) {
  const [catalog, setCatalog] = useState<CatalogState>({ status: 'loading' })
  const ready = catalog.status !== 'loading'
  const scroll = useCatalogScroll(ready)

  function navigatePage(number: number) {
    if (number === page || catalog.status !== 'success' || number < 1 || number > catalog.page.meta.last_page) return
    scroll.remember()
    onPageChange(number)
  }

  useEffect(() => {
    const controller = new AbortController()
    getGames(page, sort, controller.signal)
      .then((result) => {
        if (!controller.signal.aborted) setCatalog({ status: 'success', page: result })
      })
      .catch(() => {
        if (!controller.signal.aborted) setCatalog({ status: 'error' })
      })

    return () => controller.abort()
  }, [page, sort])

  return (
    <main className={styles.main} id="games" ref={scroll.mainRef}
      aria-busy={!ready || scroll.restoring}
      style={{ minHeight: scroll.restoring ? scroll.mainHeight : undefined }}>
      <h1 className={styles.heading}>Games</h1>
      {catalog.status === 'loading' && <p role="status" className={scroll.restoring ? styles.restoringStatus : undefined}>Loading games...</p>}
      <div style={{ visibility: scroll.restoring && ready ? 'hidden' : undefined }}>
        {catalog.status === 'error' && <p role="alert">Failed to load games.</p>}
        {catalog.status === 'success' && (
          <>
            {catalog.page.data.length === 0 ? <p role="status">No games available.</p> : (
              <div className={styles.grid}>
                {catalog.page.data.map((game) => (
                  <GameCard key={game.id} id={game.id} name={game.name} location={game.location} img={game.img} />
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
    })
  }

  return <CatalogPage key={`${location.key}:${sort}:${page}`} page={page} sort={sort} onPageChange={changePage} />
}
