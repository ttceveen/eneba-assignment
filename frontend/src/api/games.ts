export type Offer = {
  id: number
  seller: { id: number; name: string }
  price: string
  quantity: number
}

export type Game = {
  id: number
  name: string
  location: string[]
  img: string | null
  min_price?: string | null
  offers_count?: number
  offers?: Offer[]
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

function isPrice(value: unknown): value is string {
  return typeof value === 'string' && /^(0|[1-9]\d{0,7})\.\d{2}$/.test(value)
}

function isOffer(value: unknown): value is Offer {
  return isRecord(value) && isPositiveInteger(value.id)
    && isRecord(value.seller) && isPositiveInteger(value.seller.id) && typeof value.seller.name === 'string'
    && isPrice(value.price) && isPositiveInteger(value.quantity)
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

export type SearchPreview = { data: Game[]; suggestions: string[] }

export async function searchGames(page: number, query: string, signal: AbortSignal): Promise<GamesPage> {
  const params = new URLSearchParams({ q: query, page: String(page) })
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
