export type Game = {
  id: number
  name: string
  location: string[]
  img: string | null
}

async function getResponse(path: string, signal: AbortSignal): Promise<Response> {
  const baseUrl = import.meta.env.VITE_API_BASE_URL?.trim()
  if (!baseUrl) throw new Error('VITE_API_BASE_URL is not configured')

  return fetch(`${baseUrl.replace(/\/+$/, '')}${path}`, { signal })
}

export type CatalogSort = 'popular' | 'alphabetical'

export type GamesPage = {
  data: Game[]
  links: { first: string; last: string; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

function isGame(value: unknown): value is Game {
  return isRecord(value)
    && isPositiveInteger(value.id)
    && typeof value.name === 'string'
    && Array.isArray(value.location) && value.location.every((country) => typeof country === 'string')
    && (value.img === null || typeof value.img === 'string')
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === 'string'
}

function isGamesPage(value: unknown): value is GamesPage {
  if (!isRecord(value) || !Array.isArray(value.data) || !value.data.every(isGame)
    || !isRecord(value.meta) || !isRecord(value.links)) return false

  return isPositiveInteger(value.meta.current_page) && isPositiveInteger(value.meta.last_page)
    && isPositiveInteger(value.meta.per_page)
    && typeof value.meta.total === 'number' && Number.isSafeInteger(value.meta.total) && value.meta.total >= 0
    && typeof value.links.first === 'string' && typeof value.links.last === 'string'
    && isNullableString(value.links.prev) && isNullableString(value.links.next)
}

export async function getGames(page: number, sort: CatalogSort, signal: AbortSignal): Promise<GamesPage> {
  const query = new URLSearchParams()
  if (sort === 'alphabetical') query.set('sort', sort)
  query.set('page', String(page))
  const response = await getResponse(`/games?${query}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: unknown = await response.json()
  if (!isGamesPage(payload)) throw new Error('Invalid games response')

  return payload
}

export async function getGame(id: string, signal: AbortSignal): Promise<Game | null> {
  const response = await getResponse(`/games/${encodeURIComponent(id)}`, signal)
  if (response.status === 404) return null
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: unknown = await response.json()
  if (!isRecord(payload) || !isGame(payload.data)) throw new Error('Invalid game response')

  return payload.data
}
