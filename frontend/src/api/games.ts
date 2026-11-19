export type Offer = {
  id: number
  seller: { id: number; name: string }
  price: string
  quantity: number
  platform?: OfferPlatform
}

export type Game = {
  id: number
  name: string
  location: string[]
  img: string | null
  description?: string | null
  min_price?: string | null
  offers_count?: number
  offers?: Offer[]
}

export function getApiBaseUrl(): string {
  const baseUrl = import.meta.env.VITE_API_BASE_URL?.trim()
  if (!baseUrl) throw new Error('VITE_API_BASE_URL is not configured')

  return baseUrl.replace(/\/+$/, '')
}

export async function getResponse(path: string, signal: AbortSignal, options: RequestInit = {}): Promise<Response> {
  const baseUrl = getApiBaseUrl()
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  return fetch(`${baseUrl.replace(/\/+$/, '')}${path}`, { ...options, credentials: 'omit', headers, signal })
}

export const catalogPlatforms = ['Steam', 'Xbox', 'PlayStation', 'Nintendo'] as const
export type OfferPlatform = (typeof catalogPlatforms)[number]

export function readPlatformContext(search: string): OfferPlatform | undefined {
  const values = new URLSearchParams(search).getAll('platform')
  if (values.length !== 1) return undefined
  const normalized = values[0].trim().toLowerCase()
  return catalogPlatforms.find(platform => platform.toLowerCase() === normalized)
}

export const catalogRegions = ['Global', 'Europe', 'North America'] as const
export const catalogFilterKeys = ['platform', 'region', 'min_price', 'max_price', 'popular_only'] as const
export type CatalogFilterKey = typeof catalogFilterKeys[number]

export const catalogSortOptions = [
  { value: 'price_asc', label: 'Kaina: nuo mažiausios' },
  { value: 'price_desc', label: 'Kaina: nuo didžiausios' },
  { value: 'newest', label: 'Data: nuo naujausių' },
  { value: 'oldest', label: 'Data: nuo seniausių' },
  { value: 'popular', label: 'Nuo populiariausių' },
  { value: 'name_asc', label: 'Abėcėlė: A–Z' },
  { value: 'name_desc', label: 'Abėcėlė: Z–A' },
] as const

export type CatalogSort = 'alphabetical' | typeof catalogSortOptions[number]['value']

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

function isPrice(value: unknown): value is string {
  return typeof value === 'string' && /^(0|[1-9]\d{0,7})\.\d{2}$/.test(value)
}

function isOffer(value: unknown): value is Offer {
  return isRecord(value) && isPositiveInteger(value.id)
    && isRecord(value.seller) && isPositiveInteger(value.seller.id) && typeof value.seller.name === 'string'
    && isPrice(value.price) && isPositiveInteger(value.quantity)
    && (value.platform === undefined || catalogPlatforms.some(platform => platform === value.platform))
}

function hasOfferSummary(value: Record<string, unknown>): boolean {
  // Older responses can omit the additive fields; partial/invalid summaries are rejected.
  if (value.min_price === undefined && value.offers_count === undefined) return true
  return typeof value.offers_count === 'number' && Number.isSafeInteger(value.offers_count) && value.offers_count >= 0
    && (value.offers_count === 0 ? value.min_price === null : isPrice(value.min_price))
}

function isGame(value: unknown): value is Game {
  return isRecord(value)
    && isPositiveInteger(value.id)
    && typeof value.name === 'string'
    && Array.isArray(value.location) && value.location.every((country) => typeof country === 'string')
    && (value.img === null || typeof value.img === 'string')
    && (value.description === undefined || isNullableString(value.description))
    && hasOfferSummary(value)
    && (value.offers === undefined || (Array.isArray(value.offers) && value.offers.every(isOffer)))
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

export async function getGames(page: number, sort: CatalogSort, signal: AbortSignal, filters?: URLSearchParams): Promise<GamesPage> {
  const query = new URLSearchParams(filters)
  if (sort !== 'popular') query.set('sort', sort)
  query.set('page', String(page))
  const response = await getResponse(`/games?${query}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: unknown = await response.json()
  if (!isGamesPage(payload)) throw new Error('Invalid games response')

  return payload
}

export async function getRecentGames(ids: number[], signal: AbortSignal, filters?: URLSearchParams): Promise<GamesPage> {
  if (ids.length === 0) {
    return {
      data: [],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 },
    }
  }

  const params = new URLSearchParams(filters)
  // Send only the requested set, without disclosing the viewing order.
  ids.toSorted((a, b) => a - b).forEach(id => params.append('ids[]', String(id)))
  const page = await getGames(1, 'popular', signal, params)
  const byId = new Map(page.data.map(game => [game.id, game]))
  return { ...page, data: ids.flatMap(id => byId.get(id) ?? []) }
}

export async function getGame(id: string, signal: AbortSignal): Promise<Game | null> {
  const response = await getResponse(`/games/${encodeURIComponent(id)}`, signal)
  if (response.status === 404) return null
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: unknown = await response.json()
  if (!isRecord(payload) || !isGame(payload.data)) throw new Error('Invalid game response')

  return payload.data
}

export type SearchPreview = { data: Game[]; suggestions: string[] }

export async function searchGames(page: number, query: string, signal: AbortSignal, filters?: URLSearchParams, sort?: CatalogSort): Promise<GamesPage> {
  const params = new URLSearchParams(filters)
  params.set('q', query)
  if (sort) params.set('sort', sort)
  params.set('page', String(page))
  const response = await getResponse(`/games/search?${params}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  const payload: unknown = await response.json()
  if (!isGamesPage(payload)) throw new Error('Invalid games response')
  return payload
}

export async function getSearchPreview(query: string, signal: AbortSignal): Promise<SearchPreview> {
  const params = new URLSearchParams({ q: query, preview: '1' })
  const response = await getResponse(`/games/search?${params}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  const payload: unknown = await response.json()
  if (!isRecord(payload) || !Array.isArray(payload.data) || !payload.data.every(isGame)
    || !Array.isArray(payload.suggestions) || !payload.suggestions.every((phrase) => typeof phrase === 'string')) {
    throw new Error('Invalid search preview response')
  }
  return payload as SearchPreview
}

export type CartApiOffer = Omit<Offer, 'quantity'> & {
  game: Pick<Game, 'id' | 'name' | 'img'>
  currency: 'EUR'
  quantity: number
  available: boolean
}

export async function getOffers(ids: number[], signal: AbortSignal): Promise<CartApiOffer[]> {
  signal.throwIfAborted()
  const unique = [...new Set(ids)]
  if (!unique.length) return []
  if (unique.length > 100 || !unique.every(isPositiveInteger)) throw new Error('Invalid offer IDs (maximum 100)')
  const response = await getResponse(`/offers?${new URLSearchParams({ ids: unique.join(',') })}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  const payload: unknown = await response.json()
  if (!isRecord(payload) || !Array.isArray(payload.data) || !payload.data.every((value: unknown) =>
    isRecord(value) && isOffer({ ...value, quantity: 1 })
    && isRecord(value.game) && isPositiveInteger(value.game.id) && typeof value.game.name === 'string'
    && (value.game.img === null || typeof value.game.img === 'string')
    && value.currency === 'EUR' && typeof value.available === 'boolean'
    && typeof value.quantity === 'number' && Number.isSafeInteger(value.quantity) && value.quantity >= 0
    && (!value.available || value.quantity > 0)
    && unique.includes(value.id as number))) throw new Error('Invalid offers response')
  if (new Set(payload.data.map((value) => value.id)).size !== payload.data.length) throw new Error('Duplicate offers response')
  return payload.data as CartApiOffer[]
}
