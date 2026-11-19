import type { Offer, OfferPlatform } from '../../api/games'

export function initialOffer(offers: readonly Offer[], platform?: OfferPlatform): Offer | undefined {
  if (!platform) return offers[0]

  let cheapest: Offer | undefined
  for (const offer of offers) {
    if (offer.platform === platform && offer.quantity > 0 && (!cheapest || Number(offer.price) < Number(cheapest.price))) {
      cheapest = offer
    }
  }

  return cheapest ?? offers[0]
}
