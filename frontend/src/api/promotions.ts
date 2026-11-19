import { getResponse } from './games'

export type Promotion = {
  id: number
  title: string
  image_url: string
  link_url: string
}

function isPromotion(value: unknown): value is Promotion {
  return typeof value === 'object' && value !== null
    && 'id' in value && typeof value.id === 'number' && Number.isSafeInteger(value.id) && value.id > 0
    && 'title' in value && typeof value.title === 'string' && value.title.trim() !== ''
    && 'image_url' in value && typeof value.image_url === 'string'
    && 'link_url' in value && typeof value.link_url === 'string'
}

export async function getPromotions(signal: AbortSignal): Promise<Promotion[]> {
  const response = await getResponse('/promotions', signal)
  if (!response.ok) throw new Error('Failed to load promotions')

  const payload: unknown = await response.json()
  if (typeof payload !== 'object' || payload === null || !('data' in payload)
    || !Array.isArray(payload.data) || !payload.data.every(isPromotion)) {
    throw new Error('Invalid promotions response')
  }

  return payload.data
}
