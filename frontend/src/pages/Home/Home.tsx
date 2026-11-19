import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, useLocation, useSearchParams } from 'react-router'
import { catalogFilterKeys, catalogPlatforms, catalogSortOptions, getGames, getRecentGames, searchGames,
  type CatalogFilterKey, type GamesPage, type CatalogSort } from '../../api/games'
import { useCatalogScroll } from '../../hooks/useCatalogScroll'
import { getRecentlyViewedIds } from '../../utils/recentGames'
import { rememberCatalog } from '../../utils/catalogHistory'
import CatalogFilters, { QuickFilters } from './CatalogFilters'
import GameGrid from './GameGrid'
import HomeSections from './HomeSections'
import styles from './Home.module.css'

type CatalogState =
  | { status: 'loading' }
  | { status: 'success'; page: GamesPage }
  | { status: 'error' }

function CatalogPage({ page, sort, query, recent, filterQuery, onPageChange, countTarget }: { page: number; sort: CatalogSort | undefined; query: string; recent: boolean; filterQuery: string; onPageChange: (page: number) => void; countTarget?: HTMLDivElement | null }) {
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
        const result = recent
          ? await getRecentGames(getRecentlyViewedIds(), controller.signal, filters)
          : query
            ? await searchGames(page, query, controller.signal, filters, sort)
            : await getGames(page, sort ?? 'popular', controller.signal, filters)
        if (!controller.signal.aborted) setCatalog({ status: 'success', page: result })
      } catch {
        if (!controller.signal.aborted) setCatalog({ status: 'error' })
      }
    })()

    return () => controller.abort()
  }, [page, sort, query, recent, filterQuery, retry])

  const resultCount = catalog.status === 'success' && (
    <div className={styles.resultCount} role="status">
      Rasta žaidimų: {catalog.page.meta.total}
    </div>
  )

  return (
    <section className={styles.results} ref={mainRef} aria-label="Katalogo rezultatai"
      aria-busy={catalog.status === 'loading' || (loaded && restoring)}
      style={{ minHeight: restoring ? mainHeight : undefined }}>
      {catalog.status === 'loading' && <p role="status" className={restoring ? styles.restoringStatus : undefined}>Įkeliami žaidimai…</p>}
      <div style={{ visibility: restoring && loaded ? 'hidden' : undefined }}>
        {catalog.status === 'error' && <p role="alert">Nepavyko įkelti žaidimų.<button className={styles.retry} type="button" onClick={event => {
          event.currentTarget.disabled = true
          setCatalog({ status: 'loading' })
          setRetry(value => value + 1)
        }}>Bandyti dar kartą</button></p>}
        {catalog.status === 'success' && (
          <>
            {countTarget ? createPortal(resultCount, countTarget) : resultCount}
            {catalog.page.data.length === 0 ? <p role="status">{query || filterQuery ? 'Žaidimų nerasta.' : 'Nėra pasiekiamų žaidimų.'}</p> : (
              <GameGrid games={catalog.page.data} compact={!recent} />
            )}
            {catalog.page.meta.last_page > 1 && (
              <nav className={styles.pagination} aria-label="Katalogo puslapiai">
                <button type="button" aria-label="Ankstesnis puslapis" disabled={catalog.page.meta.current_page <= 1}
                  onClick={() => navigatePage(Math.min(catalog.page.meta.current_page - 1, catalog.page.meta.last_page))}>←</button>
                {Array.from({ length: catalog.page.meta.last_page }, (_, index) => index + 1).map((number) => (
                  <button key={number} type="button" aria-label={`Puslapis ${number}`}
                    aria-current={number === catalog.page.meta.current_page ? 'page' : undefined}
                    onClick={() => navigatePage(number)}>{number}</button>
                ))}
                <button type="button" aria-label="Kitas puslapis" disabled={catalog.page.meta.current_page >= catalog.page.meta.last_page}
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
  const [countTarget, setCountTarget] = useState<HTMLDivElement | null>(null)
  const location = useLocation()
  const recent = searchParams.get('view') === 'recent'
  const query = recent ? '' : searchParams.get('q')?.trim() ?? ''
  useEffect(() => { if (!query) rememberCatalog(location) }, [location, query])
  const rawSort = searchParams.get('sort')
  const sort: CatalogSort | undefined = recent ? undefined : rawSort === 'alphabetical'
    ? rawSort
    : catalogSortOptions.find(option => option.value === rawSort)?.value ?? (query ? undefined : 'popular')
  const rawPage = searchParams.get('page') ?? '1'
  const parsedPage = Number(rawPage)
  const page = /^[1-9]\d*$/.test(rawPage) && Number.isSafeInteger(parsedPage) ? parsedPage : 1

  const filters = new URLSearchParams()
  for (const key of catalogFilterKeys) {
    const value = searchParams.get(key)
    if (value) filters.set(key, value)
  }
  const platform = filters.get('platform')
  const category = !recent && !query && catalogPlatforms.find(name => name === platform)
  const canClear = catalogFilterKeys.some(key => filters.has(key) && (key !== 'platform' || !category))
  const minimum = filters.get('min_price')
  const maximum = filters.get('max_price')
  const priceError = [minimum, maximum].some(value => value !== null && (!Number.isFinite(Number(value)) || Number(value) < 0))
    ? 'Įveskite neneigiamas kainas.'
    : minimum !== null && maximum !== null && Number(minimum) > Number(maximum)
      ? 'Minimali kaina negali viršyti maksimalios kainos.'
      : null

  function changeFilter(key?: CatalogFilterKey, value?: string) {
    setSearchParams(current => {
      const next = new URLSearchParams(current)
      next.delete('page')
      if (!key) {
        catalogFilterKeys.forEach(filter => {
          if (filter !== 'platform' || !category) next.delete(filter)
        })
      } else if (value) next.set(key, value)
      else next.delete(key)
      if (!next.size) next.set('sort', 'popular')
      return next
    }, { state: location.state, replace: key === 'min_price' || key === 'max_price' })
  }

  function changeSort(value: string) {
    setSearchParams(current => {
      const next = new URLSearchParams(current)
      next.delete('page')
      if (value) next.set('sort', value)
      else next.delete('sort')
      if (!next.size) next.set('sort', 'popular')
      return next
    }, { state: location.state })
  }

  function changePage(nextPage: number) {
    if (nextPage === page || nextPage < 1) return
    setSearchParams((current) => {
      const next = new URLSearchParams(current)
      if (nextPage === 1) next.delete('page')
      else next.set('page', String(nextPage))
      if (!next.size) next.set('sort', 'popular')
      return next
    }, { state: location.state })
  }

  if (!location.search) return <HomeSections key={location.key} />

  const sorting = (
    <label className={styles.sort}>
      <span className={styles.visuallyHidden}>Rikiavimas</span>
      <select value={sort === 'alphabetical' ? 'name_asc' : sort ?? ''}
        onChange={event => changeSort(event.target.value)}>
        {query && <option value="">Pagal atitikimą</option>}
        {catalogSortOptions.map(({ value, label }) => <option key={value} value={value}>{label}</option>)}
      </select>
    </label>
  )

  return (
    <main className={recent ? styles.main : `${styles.main} ${styles.compact}`} id="games">
      <div className={styles.intro}>
        {(query || platform) && (
          <nav className={styles.breadcrumbs} aria-label="Naršymo kelias">
            <Link to="/">Žaidimai</Link>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{query ? 'Paieška' : platform}</span>
          </nav>
        )}
        <h1 className={styles.heading}>{recent ? 'Neseniai peržiūrėta' : query ? `Paieškos rezultatai pagal “${query}”` : platform ? `${platform} žaidimai` : 'Žaidimai'}</h1>
      </div>
      <div className={styles.layout}>
        {!recent && <div className={styles.countSlot} ref={setCountTarget} />}
        <CatalogFilters filters={filters} onChange={changeFilter} canClear={canClear} priceError={priceError} compact={!recent} sorting={recent ? undefined : sorting} />
        <div className={styles.content}>
          {recent && <QuickFilters filters={filters} onChange={changeFilter} canClear={canClear} />}
          <div className={recent ? `${styles.resultsArea} ${styles.recentResults}` : styles.resultsArea}>
            {priceError ? <p id="catalog-price-error" role="alert">{priceError}</p> : (
              <CatalogPage key={location.key} page={recent ? 1 : page} sort={sort} query={query} recent={recent}
                filterQuery={filters.toString()} onPageChange={changePage} countTarget={recent ? undefined : countTarget} />
            )}
          </div>
        </div>
      </div>
    </main>
  )
}
