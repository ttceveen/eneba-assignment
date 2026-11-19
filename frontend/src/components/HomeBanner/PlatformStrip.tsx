import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router'
import { catalogPlatforms } from '../../api/games'
import { categoryIcons } from './categoryIcons'
import styles from './PlatformStrip.module.css'

export default function PlatformStrip() {
  const listRef = useRef<HTMLUListElement>(null)
  const [edges, setEdges] = useState({ start: true, end: true })

  useEffect(() => {
    const list = listRef.current
    if (!list) return

    function updateEdges() {
      if (!list) return
      const start = list.scrollLeft <= 1
      const end = list.scrollLeft + list.clientWidth >= list.scrollWidth - 1
      setEdges(previous => previous.start === start && previous.end === end ? previous : { start, end })
    }

    const observer = new ResizeObserver(updateEdges)
    observer.observe(list)
    list.addEventListener('scroll', updateEdges, { passive: true })
    updateEdges()

    return () => {
      observer.disconnect()
      list.removeEventListener('scroll', updateEdges)
    }
  }, [])

  function scroll(direction: -1 | 1) {
    const list = listRef.current
    if (list) list.scrollBy({ left: direction * list.clientWidth * 0.75 })
  }

  return (
    <nav className={styles.strip} aria-label="Žaidimų kategorijos">
      <button type="button" aria-label="Slinkti kategorijas kairėn" disabled={edges.start} onClick={() => scroll(-1)}>‹</button>
      <ul className={styles.list} ref={listRef}>
        <li><Link to="/?sort=popular">{categoryIcons.Games}<span>Žaidimai</span></Link></li>
        {catalogPlatforms.map(name => (
          <li key={name}><Link to={`/?${new URLSearchParams({ platform: name })}`}>
            {categoryIcons[name]}
            <span>{name}</span>
          </Link></li>
        ))}
      </ul>
      <button type="button" aria-label="Slinkti kategorijas dešinėn" disabled={edges.end} onClick={() => scroll(1)}>›</button>
    </nav>
  )
}
