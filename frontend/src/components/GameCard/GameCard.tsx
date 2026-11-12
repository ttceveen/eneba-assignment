import { Link } from 'react-router'
import GameCover from '../GameCover/GameCover'
import styles from './GameCard.module.css'

type GameCardProps = {
  id: number
  name: string
  location: string[]
  img?: string | null
  price?: number
}

export default function GameCard({ id, name, location, img, price }: GameCardProps) {
  return (
    <article className={styles.card} data-game-id={id}>
      <Link className={styles.link} to={`/games/${id}`} aria-label={name}>
        <GameCover name={name} img={img} />
        <div className={styles.details}>
          <h2 className={styles.name}>{name}</h2>
          <p className={styles.region} aria-label={`Availability: ${location.join(', ')}`}>
            {location.join(' · ')}
          </p>
          {price !== undefined && (
            <p className={styles.price}>
              {new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(price)}
            </p>
          )}
        </div>
      </Link>
    </article>
  )
}
