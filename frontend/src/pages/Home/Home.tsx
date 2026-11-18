import { useEffect, useState } from 'react'
import { useLocation, useSearchParams } from 'react-router'
import { catalogFilterKeys, catalogPlatforms, catalogRegions, catalogSortOptions, getGames, searchGames,
  type CatalogFilterKey, type GamesPage, type CatalogSort } from '../../api/games'
import { useCatalogScroll } from '../../hooks/useCatalogScroll'
import { rememberCatalog } from '../../utils/catalogHistory'
import GameCard from '../../components/GameCard/GameCard'
import styles from './Home.module.css'

type CatalogState =
  | { status: 'loading' }
  | { status: 'success'; page: GamesPage }
  | { status: 'error' }

function CatalogPage({ page, sort, query, filterQuery, onPageChange }: { page: number; sort: CatalogSort; query: string; filterQuery: string; onPageChange: (page: number) => void }) {
  const [catalog, setCatalog] = useState<CatalogState>({ status: 'loading' })
  const [retry, setRetry] = useState(0)
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
    void (async () => {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const filters = new URLSearchParams(filterQuery)
        const result = await (query
          ? searchGames(page, query, controller.signal, filters, sort)
          : getGames(page, sort, controller.signal, filters))
        if (!controller.signal.aborted) setCatalog({ status: 'success', page: result })
      } catch {
        if (!controller.signal.aborted) setCatalog({ status: 'error' })
      }
    })()

    return () => controller.abort()
  }, [page, sort, query, filterQuery, retry])

  return (
    <section className={styles.results} ref={mainRef} aria-label="Catalog results"
      aria-busy={catalog.status === 'loading' || (loaded && restoring)}
      style={{ minHeight: restoring ? mainHeight : undefined }}>
      {catalog.status === 'loading' && <p role="status" className={restoring ? styles.restoringStatus : undefined}>Loading games...</p>}
      <div style={{ visibility: restoring && loaded ? 'hidden' : undefined }}>
        {catalog.status === 'error' && <p role="alert">Failed to load games.<button className={styles.retry} type="button" onClick={event => {
          event.currentTarget.disabled = true
          setCatalog({ status: 'loading' })
          setRetry(value => value + 1)
        }}>Retry</button></p>}
        {catalog.status === 'success' && (
          <>
            <div className={styles.resultCount} role="status">
              {catalog.page.meta.total} {catalog.page.meta.total === 1 ? 'game' : 'games'} found
            </div>
            {catalog.page.data.length === 0 ? <p role="status">{query || filterQuery ? 'No games found.' : 'No games available.'}</p> : (
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
    </section>
  )
}

export default function Home() {
  const [searchParams, setSearchParams] = useSearchParams()
  const location = useLocation()
  const query = searchParams.get('q')?.trim() ?? ''
  useEffect(() => { if (!query) rememberCatalog(location) }, [location, query])
  const rawSort = searchParams.get('sort')
  const sort: CatalogSort = rawSort === 'alphabetical'
    ? rawSort
    : catalogSortOptions.find(option => option.value === rawSort)?.value ?? 'popular'
  const rawPage = searchParams.get('page') ?? '1'
  const parsedPage = Number(rawPage)
  const page = /^[1-9]\d*$/.test(rawPage) && Number.isSafeInteger(parsedPage) ? parsedPage : 1

  const filters = new URLSearchParams()
  for (const key of catalogFilterKeys) {
    const value = searchParams.get(key)
    if (value) filters.set(key, value)
  }
  const minimum = filters.get('min_price')
  const maximum = filters.get('max_price')
  const priceError = [minimum, maximum].some(value => value !== null && (!Number.isFinite(Number(value)) || Number(value) < 0))
    ? 'Enter non-negative prices.'
    : minimum !== null && maximum !== null && Number(minimum) > Number(maximum)
      ? 'Min Price must be less than or equal to Max Price.'
      : null

  function changeFilter(key?: CatalogFilterKey, value?: string) {
    setSearchParams(current => {
      const next = new URLSearchParams(current)
      next.delete('page')
      if (!key) catalogFilterKeys.forEach(filter => next.delete(filter))
      else if (value) next.set(key, value)
      else next.delete(key)
      return next
    }, { state: location.state, replace: key === 'min_price' || key === 'max_price' })
  }

  function changeSort(value: string) {
    setSearchParams(current => {
      const next = new URLSearchParams(current)
      next.delete('page')
      if (value) next.set('sort', value)
      else next.delete('sort')
      return next
    }, { state: location.state })
  }

  function changePage(nextPage: number) {
    if (nextPage === page || nextPage < 1) return
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      if (nextPage === 1) next.delete('page')
      else next.set('page', String(nextPage))
      return next
    }, { state: location.state })
  }

  return (
    <main className={styles.main} id="games">
      <h1 className={styles.heading}>{query ? `Search results for “${query}”` : 'Games'}</h1>
      <div className={styles.filters} role="group" aria-label="Catalog filters">
        <label>
          Platform
          <select value={filters.get('platform') ?? ''} onChange={event => changeFilter('platform', event.target.value)}>
            <option value="">All Platforms</option>
            {catalogPlatforms.map(platform => <option key={platform}>{platform}</option>)}
          </select>
        </label>
        <label>
          Region
          <select value={filters.get('region') ?? ''} onChange={event => changeFilter('region', event.target.value)}>
            <option value="">All Regions</option>
            {catalogRegions.map(region => <option key={region}>{region}</option>)}
          </select>
        </label>
        <label>
          Min Price (€)
          <input type="number" min="0" step="any" value={minimum ?? ''}
            aria-invalid={priceError ? true : undefined} aria-describedby={priceError ? 'catalog-price-error' : undefined}
            onChange={event => changeFilter('min_price', event.target.value)} />
        </label>
        <label>
          Max Price (€)
          <input type="number" min="0" step="any" value={maximum ?? ''}
            aria-invalid={priceError ? true : undefined} aria-describedby={priceError ? 'catalog-price-error' : undefined}
            onChange={event => changeFilter('max_price', event.target.value)} />
        </label>
        <label className={styles.sort}>
          Sort by
          <select value={sort === 'popular' ? '' : sort === 'alphabetical' ? 'name_asc' : sort}
            onChange={event => changeSort(event.target.value)}>
            <option value="">Default</option>
            {catalogSortOptions.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <button type="button" onClick={() => changeFilter()} disabled={filters.size === 0}>Clear Filters</button>
      </div>
      {priceError ? <p id="catalog-price-error" role="alert">{priceError}</p> : (
        <CatalogPage key={location.key} page={page} sort={sort} query={query}
          filterQuery={filters.toString()} onPageChange={changePage} />
      )}
    </main>
  )
}
