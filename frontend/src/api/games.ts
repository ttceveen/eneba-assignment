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

export type GamesPage = {
  data: Game[]
  links: { first: string; last: string; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export async function getGames(page: number, signal: AbortSignal): Promise<GamesPage> {
  const response = await getResponse(`/games?page=${page}`, signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: GamesPage = await response.json()
  if (!Array.isArray(payload.data) || !payload.meta
    || !Number.isInteger(payload.meta.current_page) || payload.meta.current_page < 1
    || !Number.isInteger(payload.meta.last_page) || payload.meta.last_page < 1) {
    throw new Error('Invalid games response')
  }

  return payload
}

export async function getGame(id: string, signal: AbortSignal): Promise<Game | null> {
  const response = await getResponse(`/games/${encodeURIComponent(id)}`, signal)
  if (response.status === 404) return null
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: { data: Game } = await response.json()
  if (!payload.data || typeof payload.data.id !== 'number') throw new Error('Invalid game response')

  return payload.data
}
