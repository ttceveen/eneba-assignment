import { getOffers, type Game, type Offer } from '../api/games'
import { MAX_CART_ITEMS } from './cart'

export type CartOffer = { game: Pick<Game, 'id' | 'name' | 'img'>; offer: Offer }

export async function resolveCartOffers(offerIds: number[], signal: AbortSignal): Promise<Map<number, CartOffer>> {
  // Let a synchronous StrictMode cleanup cancel before starting HTTP I/O.
  await Promise.resolve()
  signal.throwIfAborted()
  const ids = [...new Set(offerIds.filter((id) => Number.isSafeInteger(id) && id > 0))].slice(0, MAX_CART_ITEMS)
  const offers = await getOffers(ids, signal)
  signal.throwIfAborted()
  return new Map(offers.filter((offer) => offer.available).map((offer) => [offer.id, { game: offer.game, offer }]))
}
