import type { Game } from '../../api/games'
import GameCard from '../../components/GameCard/GameCard'
import styles from './Home.module.css'

export default function GameGrid({ games, className = styles.grid, compact = false }: { games: Game[]; className?: string; compact?: boolean }) {
  return (
    <div className={className}>
      {games.map(game => (
        <GameCard key={game.id} id={game.id} name={game.name} location={game.location}
          img={game.img} price={game.min_price} offersCount={game.offers_count} compact={compact} />
      ))}
    </div>
  )
}
