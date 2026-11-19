const storageKey = 'eneba:recently-viewed-games'
const historyLimit = 12

function isGameId(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

export function getRecentlyViewedIds(): number[] {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(storageKey) ?? '[]')
    return Array.isArray(saved) ? [...new Set(saved.filter(isGameId))].slice(0, historyLimit) : []
  } catch {
    return []
  }
}

export function recordGameView(id: number): void {
  if (!isGameId(id)) return

  try {
    const previous = getRecentlyViewedIds()
    if (previous[0] === id) return
    const next = [id, ...previous.filter(savedId => savedId !== id)].slice(0, historyLimit)
    localStorage.setItem(storageKey, JSON.stringify(next))
  } catch {
    // History is optional when storage is unavailable.
  }
}
