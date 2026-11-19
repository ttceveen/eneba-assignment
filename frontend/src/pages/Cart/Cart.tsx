import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useCart } from '../../cart/context'
import { formatCents, priceInCents } from '../../cart/cart'
import { resolveCartOffers, type CartOffer } from '../../cart/resolveOffers'
import GameCover from '../../components/GameCover/GameCover'
import styles from './Cart.module.css'

type CartData = { key: string; status: 'loading' | 'error' }
  | { key: string; status: 'ready'; offers: Map<number, CartOffer> }

export default function Cart() {
  const { items, stockChanges, storageError, adjustQuantity, removeItem, rememberStock, getSnapshot, syncRevision } = useCart()
  const [revision, setRevision] = useState(0)
  const ids = items.map((item) => item.offerId).sort((a, b) => a - b).join(',')
  const requestKey = `${ids}:${revision}:${syncRevision}`
  const [data, setData] = useState<CartData>({ key: requestKey, status: 'loading' })
  const navigate = useNavigate()
  const ready = data.key === requestKey && data.status === 'ready'
  const offers = ready && data.status === 'ready' ? data.offers : new Map<number, CartOffer>()
  const failed = data.key === requestKey && data.status === 'error'
  const allAvailable = ready && items.every((item) => offers.has(item.offerId))
  const total = items.reduce((sum, item) => {
    const detail = offers.get(item.offerId)
    return detail ? sum + priceInCents(detail.offer.price) * BigInt(Math.min(item.quantity, detail.offer.quantity)) : sum
  }, 0n)

  useEffect(() => {
    if (!ids) return
    const controller = new AbortController()
    const offerIds = ids.split(',').map(Number)
    const snapshot = getSnapshot()
    resolveCartOffers(offerIds, controller.signal)
      .then((resolved) => {
        if (controller.signal.aborted) return
        if (getSnapshot() !== snapshot) { setRevision((value) => value + 1); return }
        rememberStock(new Map([...resolved].map(([id, detail]) => [id, detail.offer.quantity])), snapshot)
        setData({ key: requestKey, status: 'ready', offers: resolved })
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setData({ key: requestKey, status: 'error' })
          controller.abort()
        }
      })
    return () => controller.abort()
  }, [ids, requestKey, rememberStock, getSnapshot])

  return (
    <main className={styles.main}>
      <div className={styles.heading}>
        <h1>Krepšelis</h1>
        <Link to="/">← Tęsti apsipirkimą</Link>
      </div>
      {storageError && <p role="alert" className={styles.notice}>Naršyklės saugykla nepasiekiama. Šiame skirtuke krepšelis veikia, tačiau jo išsaugoti nepavyks.</p>}
      {items.length === 0 ? (
        <section className={styles.empty}>
          <h2>Jūsų krepšelis tuščias</h2>
          <p>Žaidimo puslapyje pasirinkite pardavėjo pasiūlymą ir pridėkite jį į krepšelį.</p>
          <Link className={styles.primary} to="/">Tęsti apsipirkimą</Link>
        </section>
      ) : (
        <>
          {!ready && !failed && <p role="status">Įkeliami krepšelio pasiūlymai…</p>}
          {failed && <p role="alert" className={styles.notice}>Nepavyko įkelti krepšelio pasiūlymų. Išsaugotos prekės liko krepšelyje. <button type="button" onClick={() => setRevision((value) => value + 1)}>Bandyti dar kartą</button></p>}
          <div className={styles.layout}>
            <section aria-label="Krepšelio prekės" aria-busy={!ready && !failed}>
              <ul className={styles.items}>
                {items.map((item) => {
                  const detail = offers.get(item.offerId)
                  const quantity = detail ? Math.min(item.quantity, detail.offer.quantity) : item.quantity
                  return (
                    <li className={styles.item} key={item.offerId} data-offer-id={item.offerId}>
                      <div className={styles.cover}><GameCover name={detail?.game.name ?? `Offer ${item.offerId}`} img={detail?.game.img} /></div>
                      <div className={styles.information}>
                        {detail ? (
                          <>
                            <h2><Link to={`/games/${detail.game.id}`}>{detail.game.name}</Link></h2>
                            <p>Pardavėjas: {detail.offer.seller.name}</p>
                            <p className={styles.stock}>Turimas kiekis: {detail.offer.quantity}</p>
                            {stockChanges.filter(change => change.offerId === item.offerId && change.to === quantity).map(change => <p key={change.offerId} role="status">Pasikeitė turimas kiekis. Kiekis pakeistas iš {change.from} į {change.to}.</p>)}
                          </>
                        ) : (
                          <>
                            <h2>Pasiūlymas Nr. {item.offerId}</h2>
                            <p className={styles.unavailable}>{ready ? 'Šis pasiūlymas nebepasiekiamas. Pašalinkite jį, kad galėtumėte tęsti.' : failed ? 'Nepavyko įkelti pasiūlymo informacijos.' : 'Įkeliama pasiūlymo informacija…'}</p>
                          </>
                        )}
                      </div>
                      <button className={styles.remove} type="button" aria-label="Pašalinti prekę iš krepšelio" onClick={() => removeItem(item.offerId)}>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
                          <path d="M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6" />
                        </svg>
                      </button>
                      <div className={styles.controls}>
                        <div><span className={styles.label}>Vieneto kaina</span><span aria-label={`Pasiūlymo vieneto kaina ${item.offerId}`}>{detail ? formatCents(priceInCents(detail.offer.price)) : '—'}</span></div>
                        <div>
                          <span className={styles.label}>Kiekis</span>
                          <div className={styles.quantity}>
                            <button type="button" aria-label={`Sumažinti pasiūlymo kiekį ${item.offerId}`} disabled={!detail || quantity <= 1} onClick={() => adjustQuantity(item.offerId, -1, detail?.offer.quantity ?? 0)}>−</button>
                            <output aria-label={`Pasiūlymo kiekis ${item.offerId}`}>{quantity}</output>
                            <button type="button" aria-label={`Padidinti pasiūlymo kiekį ${item.offerId}`} disabled={!detail || quantity >= detail.offer.quantity} onClick={() => adjustQuantity(item.offerId, 1, detail?.offer.quantity ?? 0)}>+</button>
                          </div>
                        </div>
                        <div><span className={styles.label}>Prekės suma</span><strong aria-label={`Pasiūlymo bendra suma ${item.offerId}`}>{detail ? formatCents(priceInCents(detail.offer.price) * BigInt(quantity)) : '—'}</strong></div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            </section>
            <aside className={styles.summary} aria-label="Užsakymo suvestinė">
              <h2>Užsakymo suvestinė</h2>
              <div className={styles.total}><span>Iš viso</span><strong aria-label="Krepšelio suma">{ready ? formatCents(total) : '—'}</strong></div>
              {ready && !allAvailable && <p className={styles.unavailable}>Nebepasiekiami pasiūlymai neįtraukti į bendrą sumą. Prieš tęsdami juos pašalinkite.</p>}
              <p>Įdėjus prekę į krepšelį, raktai nerezervuojami. Kainos ir turimas kiekis bus patikrinti įforminant užsakymą.</p>
              <div className={styles.checkoutBar} aria-label="Užsakymo veiksmai">
                <div className={styles.mobileTotal}><span>Iš viso</span><strong aria-label="Krepšelio suma mobiliajame įrenginyje">{ready ? formatCents(total) : '—'}</strong></div>
                <button className={styles.primary} type="button" disabled={!allAvailable} onClick={() => navigate('/checkout')}>Įforminti užsakymą</button>
              </div>
              <button type="button" className={styles.refresh} onClick={() => setRevision((value) => value + 1)}>Atnaujinti kainas</button>
              <Link className={styles.continue} to="/">Tęsti apsipirkimą</Link>
            </aside>
          </div>
        </>
      )}
    </main>
  )
}
