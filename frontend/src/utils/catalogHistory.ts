type CatalogEntry = { key: string; url: string; index: number | null }
type RouterLocation = { key: string; pathname: string; search: string; state: unknown }

function isEntry(value: unknown): value is CatalogEntry {
  if (!value || typeof value !== 'object') return false
  const entry = value as Partial<CatalogEntry>
  return typeof entry.key === 'string' && typeof entry.url === 'string'
    && (entry.url === '/' || entry.url.startsWith('/?'))
    && !new URLSearchParams(entry.url.split('?')[1]).get('q')?.trim()
    && (entry.index === null || (typeof entry.index === 'number' && Number.isInteger(entry.index) && entry.index >= 0))
}

export function rememberCatalog(location: RouterLocation) {
  const entry = { key: location.key, url: location.pathname + location.search, index: history.state?.idx }
  if (location.pathname !== '/' || !isEntry(entry) || entry.index === null) return
  try { sessionStorage.setItem('eneba:last-catalog', JSON.stringify(entry)) } catch { /* Optional storage. */ }
}

export function catalogReturn(location: RouterLocation): CatalogEntry | null {
  if (location.pathname === '/' && !new URLSearchParams(location.search).get('q')?.trim()) {
    const current = { key: location.key, url: location.pathname + location.search, index: history.state?.idx }
    if (isEntry(current)) return current
  }
  const state = location.state as { catalogReturn?: unknown } | null
  if (isEntry(state?.catalogReturn)) return state.catalogReturn
  try {
    const saved: unknown = JSON.parse(sessionStorage.getItem('eneba:last-catalog') ?? 'null')
    // Session storage alone cannot prove that an old history index is still reachable.
    if (isEntry(saved)) return { ...saved, index: null }
  } catch { /* No previous catalog entry. */ }
  return null
}
