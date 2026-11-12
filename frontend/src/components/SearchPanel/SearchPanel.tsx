import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useNavigationType } from 'react-router'
import { getSearchPreview, type SearchPreview } from '../../api/games'
import { catalogReturn } from '../../utils/catalogHistory'
import GameCover from '../GameCover/GameCover'
import styles from './SearchPanel.module.css'

type PreviewState = { query: string; status: 'loading' | 'error' }
  | { query: string; status: 'success'; result: SearchPreview }

function readDraft(key: string, url: string): { open: boolean; query: string } | null {
  try {
    const saved = JSON.parse(sessionStorage.getItem(key) ?? 'null')
    if (saved?.url === url && typeof saved.open === 'boolean' && typeof saved.query === 'string') return saved
  } catch { /* Optional storage. */ }
  return null
}

export default function SearchPanel() {
  const location = useLocation()
  const navigation = useNavigationType()
  const navigate = useNavigate()
  const confirmed = new URLSearchParams(location.search).get('q')?.trim() ?? ''
  const storageKey = `eneba:search-panel:${location.key}`
  const [draft] = useState(() => navigation === 'POP' ? readDraft(storageKey, location.pathname + location.search) : null)
  const [open, setOpen] = useState(draft?.open ?? false)
  const [query, setQuery] = useState(draft?.query ?? confirmed)
  const [preview, setPreview] = useState<PreviewState | null>(null)
  const dialog = useRef<HTMLDialogElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const trigger = useRef<HTMLInputElement>(null)
  const normalized = query.trim()
  const validQuery = normalized.length > 0 && normalized.length <= 255
  const current = preview?.query === normalized ? preview : null

  function store(isOpen: boolean, text = query) {
    try { sessionStorage.setItem(storageKey, JSON.stringify({ url: location.pathname + location.search, open: isOpen, query: text })) } catch { /* Optional storage. */ }
  }
  function openPanel() {
    store(true)
    setPreview(normalized ? { query: normalized, status: 'loading' } : null)
    setOpen(true)
  }
  function close() {
    store(false)
    setOpen(false)
    trigger.current?.focus({ preventScroll: true })
  }
  function submit(text: string) {
    const value = text.trim()
    if (!value || value.length > 255) return
    const previous = catalogReturn(location)
    store(false, value)
    setOpen(false)
    navigate(`/?${new URLSearchParams({ q: value })}`, { state: { catalogReturn: previous } })
  }
  function clearSearch() {
    const previous = catalogReturn(location)
    store(false, '')
    setOpen(false)
    if (previous) {
      try { sessionStorage.removeItem(`eneba:search-panel:${previous.key}`) } catch { /* Optional storage. */ }
    }
    const index = history.state?.idx
    if (previous && previous.index !== null && Number.isInteger(index) && previous.index < index) navigate(previous.index - index)
    else navigate(previous?.url ?? '/', { state: { restoreCatalogKey: previous?.key } })
  }

  useLayoutEffect(() => {
    if (!open) return
    const element = dialog.current
    const previousOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
    element?.showModal()
    input.current?.focus({ preventScroll: true })
    return () => {
      element?.close()
      document.documentElement.style.overflow = previousOverflow
    }
  }, [open])

  useEffect(() => {
    if (!open || !normalized || normalized.length > 255) return
    const controller = new AbortController()
    const timer = setTimeout(() => {
      getSearchPreview(normalized, controller.signal)
        .then((result) => { if (!controller.signal.aborted) setPreview({ query: normalized, status: 'success', result }) })
        .catch(() => { if (!controller.signal.aborted) setPreview({ query: normalized, status: 'error' }) })
    }, 280)
    return () => { clearTimeout(timer); controller.abort() }
  }, [open, normalized])

  return (
    <>
      <div className={styles.trigger}>
        <div className={styles.triggerField}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" /></svg>
          <input ref={trigger} type="search" aria-label="Search games" aria-haspopup="dialog"
            placeholder="Search for games" value={confirmed}
            onFocus={(event) => {
              // Closing a modal returns focus here; it must not reopen the panel.
              if (event.relatedTarget instanceof Element && event.relatedTarget.closest('dialog')) return
              openPanel()
            }}
            onClick={openPanel}
            onChange={(event) => {
              const value = event.target.value
              setQuery(value); store(true, value)
              setPreview(value.trim() ? { query: value.trim(), status: 'loading' } : null)
              setOpen(true)
            }} />
        </div>
        {confirmed && <button type="button" className={styles.clearConfirmed} onClick={clearSearch} aria-label="Clear search">×</button>}
      </div>
      <dialog ref={dialog} className={styles.panel} aria-labelledby="search-title"
        onKeyDown={(event) => {
          if (event.key === 'Escape') { event.preventDefault(); close() }
        }}
        onCancel={(event) => { event.preventDefault(); close() }}
        onClick={(event) => { if (event.target === event.currentTarget) close() }}>
        <div className={styles.content}>
          <form className={styles.form} onSubmit={(event) => { event.preventDefault(); submit(query) }}>
            <button type="button" className={styles.back} onClick={close} aria-label="Close search">←</button>
            <label id="search-title" className={styles.visuallyHidden} htmlFor="search-query">Search games</label>
            <input ref={input} id="search-query" type="search" autoComplete="off" maxLength={255}
              placeholder="Search for games" value={query}
              onChange={(event) => {
                const value = event.target.value
                setQuery(value); store(true, value)
                setPreview(value.trim() ? { query: value.trim(), status: 'loading' } : null)
              }} />
            {query && <button type="button" className={styles.clear} aria-label="Clear search text" onClick={() => {
              if (confirmed && normalized === confirmed) { clearSearch(); return }
              setQuery(''); store(true, ''); setPreview(null); input.current?.focus({ preventScroll: true })
            }}>×</button>}
            <button type="submit" className={styles.submit} disabled={!validQuery}>Search</button>
          </form>
          <div className={styles.results} aria-live="polite" aria-busy={Boolean(validQuery && (!current || current.status === 'loading'))}>
            {!normalized && <p className={styles.status}>Start typing a game title.</p>}
            {normalized.length > 255 && <p role="alert" className={styles.status}>Search query must be 255 characters or fewer.</p>}
            {validQuery && (!current || current.status === 'loading') && <p className={styles.status}>Searching games...</p>}
            {current?.status === 'error' && <p role="alert" className={styles.status}>Failed to search games.</p>}
            {current?.status === 'success' && (
              <>
                {current.result.data.length === 0 ? <p className={styles.status}>No games found.</p> : (
                  <>
                    <section className={styles.suggestions} aria-label="Search suggestions">
                      <h2>Suggestions</h2>
                      {current.result.suggestions.map((phrase) => <button type="button" key={phrase} onClick={() => submit(phrase)}><span aria-hidden="true">↗</span>{phrase}</button>)}
                    </section>
                    <section className={styles.games} aria-label="Search preview">
                      <h2>Games</h2>
                      {current.result.data.map((game) => <Link key={game.id} to={`/games/${game.id}`} onClick={() => store(true)} state={{ catalogReturn: catalogReturn(location) }}>
                        <div className={styles.cover}><GameCover name={game.name} img={game.img} /></div><span>{game.name}</span>
                      </Link>)}
                    </section>
                    <button type="button" className={styles.allResults} onClick={() => submit(query)}>View all results for “{normalized}” →</button>
                  </>
                )}
              </>
            )}
          </div>
        </div>
      </dialog>
    </>
  )
}
