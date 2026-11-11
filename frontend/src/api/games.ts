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

export async function getGames(signal: AbortSignal): Promise<Game[]> {
  const response = await getResponse('/games', signal)
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: { data: Game[] } = await response.json()
  if (!Array.isArray(payload.data)) throw new Error('Invalid games response')

  return payload.data
}

export async function getGame(id: string, signal: AbortSignal): Promise<Game | null> {
  const response = await getResponse(`/games/${encodeURIComponent(id)}`, signal)
  if (response.status === 404) return null
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  const payload: { data: Game } = await response.json()
  if (!payload.data || typeof payload.data.id !== 'number') throw new Error('Invalid game response')

  return payload.data
}
