import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import { useLocation, useNavigationType } from 'react-router'

type CatalogPosition = {
  url: string
  y: number
  mainHeight: number
  anchorId: string | null
  anchorOffset: number
}

function readPosition(key: string, url: string): CatalogPosition | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(key) ?? 'null') as CatalogPosition | null
    if (value?.url === url && Number.isFinite(value.y) && value.y >= 0
      && Number.isFinite(value.mainHeight) && value.mainHeight > 0
      && (value.anchorId === null || typeof value.anchorId === 'string')
      && Number.isFinite(value.anchorOffset)) return value
  } catch { /* Storage may be unavailable; normal navigation still works. */ }
  return null
}

export function useCatalogScroll(loaded: boolean) {
  const location = useLocation()
  const navigation = useNavigationType()
  const key = `eneba:catalog:${location.key}`
  const url = location.pathname + location.search
  const mainRef = useRef<HTMLElement>(null)
  const [target] = useState(() => navigation === 'POP' ? readPosition(key, url) : null)
  const [restoring, setRestoring] = useState(target !== null)
  const anchorId = useRef(target?.anchorId ?? null)

  const remember = useCallback((clickedCard?: HTMLElement) => {
    const main = mainRef.current
    // Failed requests must never replace the last successful catalog snapshot.
    if (!main || !loaded || restoring) return
    const cards = [...main.querySelectorAll<HTMLElement>('[data-game-id]')]
    const visible = (card: HTMLElement) => {
      const box = card.getBoundingClientRect()
      return box.bottom > 0 && box.top < innerHeight
    }
    const anchor = clickedCard
      ?? cards.find((card) => card.dataset.gameId === anchorId.current && visible(card))
      ?? cards.find(visible)
    anchorId.current = anchor?.dataset.gameId ?? null
    const position: CatalogPosition = {
      url, y: scrollY, mainHeight: main.getBoundingClientRect().height,
      anchorId: anchorId.current, anchorOffset: anchor?.getBoundingClientRect().top ?? 0,
    }
    try { sessionStorage.setItem(key, JSON.stringify(position)) } catch { /* Optional browser storage. */ }
  }, [key, url, loaded, restoring])

  useLayoutEffect(() => {
    // POP restoration waits for API data. PUSH/REPLACE begins at the catalog top.
    if (!target) window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
  }, [target])

  useLayoutEffect(() => {
    const main = mainRef.current
    if (!loaded || !restoring || !target || !main) return
    // Remove the loading-height reservation before measuring the final grid.
    main.style.minHeight = ''
    const anchor = [...main.querySelectorAll<HTMLElement>('[data-game-id]')]
      .find((card) => card.dataset.gameId === target.anchorId)
    const y = anchor ? scrollY + anchor.getBoundingClientRect().top - target.anchorOffset : target.y
    const maximum = Math.max(0, document.documentElement.scrollHeight - innerHeight)
    window.scrollTo({ top: Math.max(0, Math.min(y, maximum)), left: 0, behavior: 'instant' })
    setRestoring(false)
  }, [loaded, restoring, target])

  useLayoutEffect(() => {
    if (!loaded || restoring) return
    let frame = 0
    const onScroll = () => {
      if (!frame) frame = requestAnimationFrame(() => { frame = 0; remember() })
    }
    const onClick = (event: MouseEvent) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return
      if (!(event.target instanceof Element) || !event.target.closest('a')) return
      remember(event.target.closest<HTMLElement>('[data-game-id]') ?? undefined)
    }
    const save = () => remember()
    addEventListener('scroll', onScroll, { passive: true })
    addEventListener('click', onClick, true)
    addEventListener('pagehide', save)
    addEventListener('popstate', save)
    return () => {
      cancelAnimationFrame(frame)
      removeEventListener('scroll', onScroll)
      removeEventListener('click', onClick, true)
      removeEventListener('pagehide', save)
      removeEventListener('popstate', save)
    }
  }, [loaded, restoring, remember])

  return { mainRef, remember, restoring, mainHeight: target?.mainHeight }
}
