import { getGame, getGames, type Game, type Offer } from '../api/games'

export type CartOffer = { game: Game; offer: Offer }

export async function resolveCartOffers(
  offerIds: number[], hints: ReadonlyMap<number, number>, signal: AbortSignal,
): Promise<Map<number, CartOffer>> {
  signal.throwIfAborted()
  const wanted = new Set(offerIds)
  const result = new Map<number, CartOffer>()
  const checked = new Set<number>()

  async function loadGames(ids: number[]) {
    for (let start = 0; start < ids.length && wanted.size; start += 4) {
      signal.throwIfAborted()
      const batch = ids.slice(start, start + 4).filter((id) => !checked.has(id))
      for (const id of batch) checked.add(id)
      const games = await Promise.all(batch.map((id) => getGame(String(id), signal)))
      signal.throwIfAborted()
      for (const game of games) {
        if (game && game.offers === undefined) throw new Error('Game details do not provide seller offers')
        for (const offer of game?.offers ?? []) {
          if (game && wanted.has(offer.id)) {
            result.set(offer.id, { game, offer })
            wanted.delete(offer.id)
          }
        }
      }
    }
  }

  await loadGames([...new Set(offerIds.flatMap((id) => hints.has(id) ? [hints.get(id)!] : []))])
  // The current API has no lookup by offerId. Scan active games only as a cold-start fallback.
  for (let page = 1; wanted.size; page++) {
    signal.throwIfAborted()
    const catalog = await getGames(page, 'alphabetical', signal)
    await loadGames(catalog.data.filter((game) => game.offers_count !== 0).map((game) => game.id))
    if (page >= catalog.meta.last_page) break
  }
  return result
}
