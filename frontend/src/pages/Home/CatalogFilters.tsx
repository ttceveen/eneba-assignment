import { useRef, useState, type ReactNode } from 'react'
import { catalogPlatforms, catalogRegions, type CatalogFilterKey } from '../../api/games'
import styles from './Home.module.css'

type FiltersProps = {
  filters: URLSearchParams
  onChange: (key?: CatalogFilterKey, value?: string) => void
  canClear: boolean
}

const regionLabels: Record<(typeof catalogRegions)[number], string> = {
  Global: 'Visame pasaulyje',
  Europe: 'Europa',
  'North America': 'Šiaurės Amerika',
}

function optionLabel(option: string) {
  const region = catalogRegions.find(value => value === option)
  return region ? regionLabels[region] : option
}

const choices = [
  { key: 'platform', title: 'Platformos', all: 'Visos platformos', options: catalogPlatforms },
  { key: 'region', title: 'Regionai', all: 'Visi regionai', options: catalogRegions },
] as const

function ClearFilters({ canClear, onChange }: Pick<FiltersProps, 'canClear' | 'onChange'>) {
  return <button type="button" className={styles.clearFilters} onClick={() => onChange()} disabled={!canClear}>Išvalyti viską</button>
}

function PopularToggle({ filters, onChange }: Pick<FiltersProps, 'filters' | 'onChange'>) {
  return (
    <label className={styles.popularToggle}>
      <input type="checkbox" checked={filters.get('popular_only') === '1'}
        onChange={event => onChange('popular_only', event.target.checked ? '1' : '')} />
      Populiarūs
    </label>
  )
}

export function QuickFilters({ filters, onChange, canClear, leading, sorting }: FiltersProps & { leading?: ReactNode; sorting?: ReactNode }) {
  return (
    <div className={styles.quickFilters} role="group" aria-label="Greitieji filtrai">
      {leading}
      {choices.map(({ key, title, options }) => {
        const value = filters.get(key) ?? ''
        return (
          <select key={key} aria-label={title} value={value} onChange={event => onChange(key, event.target.value)}>
            <option value="">{title}</option>
            {options.map(option => <option key={option} value={option}>{optionLabel(option)}</option>)}
          </select>
        )
      })}
      {sorting}
      <PopularToggle filters={filters} onChange={onChange} />
      <ClearFilters canClear={canClear} onChange={onChange} />
    </div>
  )
}

export default function CatalogFilters({ filters, onChange, canClear, priceError, compact, sorting }: FiltersProps & { priceError: string | null; compact: boolean; sorting?: ReactNode }) {
  const [expanded, setExpanded] = useState(false)

  const toggle = useRef<HTMLButtonElement>(null)
  const toggleButton = (
    <button ref={toggle} className={styles.filterToggle} type="button" aria-label="Filtrai" aria-expanded={expanded}
      aria-controls="catalog-filter-fields" aria-describedby={compact && filters.size > 0 ? 'catalog-active-filter-count' : undefined}
      onClick={() => setExpanded(previous => !previous)}>
      Filtrai
      {compact && filters.size > 0 && <span id="catalog-active-filter-count" className={styles.filterCount}>{filters.size}</span>}
    </button>
  )

  return (
    <aside className={styles.filters} aria-label="Katalogo filtrai" onKeyDown={event => {
      if (event.key === 'Escape' && expanded && toggle.current?.offsetWidth) {
        event.preventDefault()
        setExpanded(false)
        toggle.current.focus()
      }
    }}>
      {compact
        ? <QuickFilters filters={filters} onChange={onChange} canClear={canClear} leading={toggleButton} sorting={sorting} />
        : <div className={styles.filterHeading}>
            <h2>Filtrai</h2>
            {toggleButton}
            <ClearFilters canClear={canClear} onChange={onChange} />
          </div>}
      <div id="catalog-filter-fields" className={styles.filterBody} data-expanded={expanded}>
        <details className={styles.filterGroup} open>
          <summary>Kainos intervalas (EUR)</summary>
          <div className={styles.priceFields}>
            <label>
              Min. kaina (€)
              <input type="number" min="0" step="any" value={filters.get('min_price') ?? ''}
                aria-invalid={priceError ? true : undefined} aria-describedby={priceError ? 'catalog-price-error' : undefined}
                onChange={event => onChange('min_price', event.target.value)} />
            </label>
            <label>
              Maks. kaina (€)
              <input type="number" min="0" step="any" value={filters.get('max_price') ?? ''}
                aria-invalid={priceError ? true : undefined} aria-describedby={priceError ? 'catalog-price-error' : undefined}
                onChange={event => onChange('max_price', event.target.value)} />
            </label>
          </div>
        </details>
        {choices.map(({ key, title, all, options }) => {
          const selected = filters.get(key) ?? ''
          return (
            <details key={key} className={styles.filterGroup} open>
              <summary>{title}{selected && <span>{optionLabel(selected)}</span>}</summary>
              <div className={styles.filterOptions} role="radiogroup" aria-label={title}>
                <label>
                  <input type="radio" name={key} checked={!selected} onChange={() => onChange(key, '')} />
                  {all}
                </label>
                {options.map(option => (
                  <label key={option}>
                    <input type="radio" name={key} checked={selected === option} onChange={() => onChange(key, option)} />
                    {optionLabel(option)}
                  </label>
                ))}
              </div>
            </details>
          )
        })}
        {compact && <div className={styles.mobilePopular}><PopularToggle filters={filters} onChange={onChange} /></div>}
      </div>
    </aside>
  )
}
