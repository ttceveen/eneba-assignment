import { useEffect, useState } from 'react'
import { useLocation } from 'react-router'
import { getPromotions, type Promotion } from '../../api/promotions'
import PlatformStrip from './PlatformStrip'
import styles from './HomeBanner.module.css'

function safeUrl(value: string): string | undefined {
  const trimmed = value.trim()
  if (!trimmed || trimmed === '#') return undefined
  const url = URL.parse(trimmed, window.location.origin)
  if (url?.protocol === 'https:' || url?.protocol === 'http:') return url.href
}

function PromotionBanner() {
  const [promotion, setPromotion] = useState<Promotion | null>()

  useEffect(() => {
    const controller = new AbortController()

    async function load() {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const [first] = await getPromotions(controller.signal)
        if (!controller.signal.aborted) setPromotion(first ?? null)
      } catch {
        if (!controller.signal.aborted) setPromotion(null)
      }
    }

    void load()
    return () => controller.abort()
  }, [])

  if (promotion === undefined) return <div className={styles.banner} aria-hidden="true" />
  if (promotion === null) return null

  const imageUrl = safeUrl(promotion.image_url)
  if (!imageUrl) return null

  const href = safeUrl(promotion.link_url)
  const artwork = <img src={imageUrl} width={1500} height={400} alt={promotion.title} fetchPriority="high" />

  return href
    ? <a className={styles.banner} href={href}>{artwork}</a>
    : <div className={styles.banner}>{artwork}</div>
}

export default function HomeBanner() {
  const { search } = useLocation()
  if (search) return null

  return (
    <>
      <PromotionBanner />
      <PlatformStrip />
    </>
  )
}
