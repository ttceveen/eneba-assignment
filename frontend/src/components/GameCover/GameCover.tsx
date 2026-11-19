import { useState } from 'react'
import styles from './GameCover.module.css'

type GameCoverProps = {
  name: string
  img?: string | null
}

export default function GameCover({ name, img }: GameCoverProps) {
  const [failedImage, setFailedImage] = useState<string | null>(null)

  return img && img !== failedImage ? (
    <img className={styles.cover} src={img} alt={`${name} viršelis`} width="600" height="900"
      onError={() => setFailedImage(img)} />
  ) : (
    <div className={`${styles.cover} ${styles.placeholder}`} role="img" aria-label={`${name}: viršelis nepasiekiamas`}>
      <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
        <rect x="6" y="6" width="36" height="36" rx="3" />
        <circle cx="17" cy="17" r="4" />
        <path d="m6 35 12-12 9 9 6-6 9 9" />
      </svg>
      <span>Viršelis nepasiekiamas</span>
    </div>
  )
}
