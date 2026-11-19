import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { getGame, readPlatformContext, type Game } from '../../api/games'
import GameCover from '../../components/GameCover/GameCover'
import { useCart } from '../../cart/context'
import AddToCartButton from '../../components/AddToCartButton/AddToCartButton'
import { catalogReturn } from '../../utils/catalogHistory'
import { recordGameView } from '../../utils/recentGames'
import styles from './GameDetails.module.css'
import { initialOffer } from './offerSelection'

type GameState =
  | { status: 'loading' }
  | { status: 'success'; game: Game }
  | { status: 'not-found' }
  | { status: 'error' }

function ProductLayout({ game, search }: { game: Game; search: string }) {
  const offers = game.offers ?? []
  const { stockChanges } = useCart()
  const [manualOfferId, setManualOfferId] = useState<number>()
  const SellerLabel = offers.length > 1 ? 'label' : 'span'
  const selected = offers.find(offer => offer.id === manualOfferId) ?? initialOffer(offers, readPlatformContext(search))

  return (
    <div className={styles.layout}>
      <div className={styles.cover}>
        <GameCover name={game.name} img={game.img} />
      </div>
      <section className={styles.information}>
        <h1>{game.name}</h1>
        <dl className={styles.facts}>
          <div>
            <dt>Regionas</dt>
            <dd>{game.location.includes('GLOBAL') ? 'Visame pasaulyje' : game.location.join(', ') || 'Nenurodyta'}</dd>
          </div>
          {selected?.platform && (
            <div>
              <dt>Platforma</dt>
              <dd>{selected.platform}</dd>
            </div>
          )}
          <div>
            <dt>Formatas</dt>
            <dd>Skaitmeninis raktas</dd>
          </div>
        </dl>
      </section>
      <section className={styles.offers} aria-label="Pardavėjų pasiūlymai">
        <h2>Pardavėjų pasiūlymai</h2>
        {offers.length ? (
          <ul className={styles.offerList}>
            {offers.map((offer) => (
              <li key={offer.id} className={offer.id === selected?.id ? `${styles.offer} ${styles.selected}` : styles.offer}>
                <SellerLabel className={styles.seller}>
                  {offers.length > 1 && <input type="radio" name="selected-offer" aria-describedby={`offer-price-${offer.id}`} checked={offer.id === selected?.id}
                    onChange={() => setManualOfferId(offer.id)} />}
                  <span className={styles.sellerDetails}>
                    <span>{offer.seller.name}</span>
                    {offer.platform && <span className={styles.platform}>{offer.platform}</span>}
                  </span>
                </SellerLabel>
                <span id={`offer-price-${offer.id}`} className={styles.offerPrice}>{new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' }).format(Number(offer.price))}</span>
                <span className={styles.quantity}>Turimas kiekis: {offer.quantity}</span>
                {stockChanges.filter(change => change.offerId === offer.id).map(change => <p key={change.offerId} role="status" className={styles.stockNotice}>Pasikeitė turimas kiekis. Kiekis pakeistas iš {change.from} į {change.to}.</p>)}
                <AddToCartButton offer={offer} />
              </li>
            ))}
          </ul>
        ) : <p className={styles.noOffers}>Pasiūlymų nėra</p>}
      </section>
    </div>
  )
}

function GameContent({ id }: { id: string }) {
  const { rememberGame, getSnapshot } = useCart()
  const location = useLocation(), navigate = useNavigate()
  const source = (location.state as { catalogReturn?: unknown } | null)?.catalogReturn
  const previous = source ? catalogReturn(location, true) : null
  const [retry, setRetry] = useState(0)
  const [state, setState] = useState<GameState>({ status: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    const snapshot = getSnapshot()
    void (async () => {
      await Promise.resolve()
      if (controller.signal.aborted) return
      try {
        const game = await getGame(id, controller.signal)
        if (!controller.signal.aborted) {
          if (game) {
            recordGameView(game.id)
            rememberGame(game, snapshot)
          }
          setState(game ? { status: 'success', game } : { status: 'not-found' })
        }
      } catch {
        if (!controller.signal.aborted) setState({ status: 'error' })
      }
    })()

    return () => controller.abort()
  }, [id, rememberGame, getSnapshot, retry])

  return (
    <main className={styles.main}>
      <Link className={styles.back} to={previous?.url ?? '/'} state={{ restoreCatalogKey: previous?.key }} onClick={event => {
        const index = history.state?.idx
        if (!event.defaultPrevented && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && previous?.index !== null && previous?.index !== undefined && Number.isInteger(index) && previous.index < index) {
          event.preventDefault()
          navigate(previous.index - index)
        }
      }}>← Žaidimai</Link>
      {state.status === 'loading' && <p role="status">Įkeliamas žaidimas…</p>}
      {state.status === 'not-found' && <p role="alert">Žaidimas nerastas.</p>}
      {state.status === 'error' && <p role="alert">Nepavyko įkelti žaidimo.<button className={styles.retry} type="button" onClick={event => {
        event.currentTarget.disabled = true
        setState({ status: 'loading' })
        setRetry(value => value + 1)
      }}>Bandyti dar kartą</button></p>}
      {state.status === 'success' && <ProductLayout key={location.search} game={state.game} search={location.search} />}
    </main>
  )
}

export default function GameDetails() {
  const { id = '' } = useParams<{ id: string }>()

  return <GameContent key={id} id={id} />
}
