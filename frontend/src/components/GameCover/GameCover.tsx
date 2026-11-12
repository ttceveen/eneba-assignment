import styles from './GameCover.module.css'

type GameCoverProps = {
  name: string
  img?: string | null
}

export default function GameCover({ name, img }: GameCoverProps) {
  return img ? (
    <img className={styles.cover} src={img} alt={`${name} cover art`} width="600" height="900" />
  ) : (
    <div className={`${styles.cover} ${styles.placeholder}`} role="img" aria-label={`${name}: cover not available`}>
      <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true">
        <rect x="6" y="6" width="36" height="36" rx="3" />
        <circle cx="17" cy="17" r="4" />
        <path d="m6 35 12-12 9 9 6-6 9 9" />
      </svg>
      <span>No cover available</span>
    </div>
  )
}
