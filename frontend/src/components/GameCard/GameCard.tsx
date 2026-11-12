import { Link, useLocation } from 'react-router'
import { catalogReturn } from '../../utils/catalogHistory'
import GameCover from '../GameCover/GameCover'
import styles from './GameCard.module.css'

type GameCardProps = {
  id: number
  name: string
  location: string[]
  img?: string | null
  price?: string | number | null
  offersCount?: number
}

export default function GameCard({ id, name, location, img, price, offersCount = 0 }: GameCardProps) {
  const routerLocation = useLocation()
  const plural = new Intl.PluralRules('ru').select(offersCount)
  const offerWord = plural === 'one' ? 'предложение' : plural === 'few' ? 'предложения' : 'предложений'
  return (
    <article className={styles.card} data-game-id={id}>
      <Link className={styles.link} to={`/games/${id}`} state={{ catalogReturn: catalogReturn(routerLocation) }} aria-label={name}>
        <GameCover name={name} img={img} />
        <div className={styles.details}>
          <h2 className={styles.name}>{name}</h2>
          <p className={styles.region} aria-label={`Availability: ${location.join(', ')}`}>
            {location.join(' · ')}
          </p>
          <div className={styles.offers}>
            {offersCount > 0 && price !== undefined && price !== null ? (
              <>
                <p className={styles.price}>От {new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(Number(price))}</p>
                <p className={styles.offerCount}>{offersCount} {offerWord}</p>
              </>
            ) : <p className={styles.noOffers}>Нет предложений</p>}
          </div>
        </div>
      </Link>
    </article>
  )
}
