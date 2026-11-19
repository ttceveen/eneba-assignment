import { useEffect, useRef, useState } from 'react'
import type { Game } from '../../api/games'
import GameGrid from './GameGrid'
import styles from './Home.module.css'

export default function GameCarousel({ games }: { games: Game[] }) {
  const trackRef = useRef<HTMLDivElement>(null)
  const [scroll, setScroll] = useState({ progress: 0, visible: 1 })

  useEffect(() => {
    const track = trackRef.current
    if (!track) return

    function update() {
      if (!track) return
      const max = track.scrollWidth - track.clientWidth
      const visible = max > 1 ? track.clientWidth / track.scrollWidth : 1
      const progress = max > 1 ? Math.min(1, Math.max(0, track.scrollLeft / max)) : 0
      setScroll(previous => previous.progress === progress && previous.visible === visible ? previous : { progress, visible })
    }

    const observer = new ResizeObserver(update)
    observer.observe(track)
    track.addEventListener('scroll', update, { passive: true })
    update()
    return () => {
      observer.disconnect()
      track.removeEventListener('scroll', update)
    }
  }, [games.length])

  function move(direction: number) {
    const track = trackRef.current
    if (track) track.scrollBy({ left: direction * track.clientWidth * 0.85 })
  }

  return (
    <div className={styles.carousel}>
      <div className={styles.carouselTrack} ref={trackRef} aria-label="Žaidimų karuselė" role="group">
        <GameGrid games={games} className={styles.carouselCards} />
      </div>
      {scroll.visible < 1 && (
        <div className={styles.carouselControls}>
          <button className={styles.previousSlide} type="button" aria-label="Slinkti kairėn"
            onClick={() => move(-1)} disabled={scroll.progress <= 0.001}>‹</button>
          <div className={styles.carouselIndicator} role="progressbar" aria-label="Karuselės padėtis"
            aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(scroll.progress * 100)}>
            <div style={{ width: `${scroll.visible * 100}%`, marginInlineStart: `${(1 - scroll.visible) * scroll.progress * 100}%` }} />
          </div>
          <button className={styles.nextSlide} type="button" aria-label="Slinkti dešinėn"
            onClick={() => move(1)} disabled={scroll.progress >= 0.999}>›</button>
        </div>
      )}
    </div>
  )
}
