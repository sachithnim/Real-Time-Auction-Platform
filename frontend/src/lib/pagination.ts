export type PageItem = number | 'gap'

/**
 * The page buttons to show: always the first and last page, the current page with one
 * either side, and a gap ("…") where pages are skipped. A gap of one page shows that page instead.
 * e.g. page 6 of 12 → 1 … 5 6 7 … 12
 */
export function pageItems(current: number, last: number): PageItem[] {
  const pages = new Set([1, last, current - 1, current, current + 1].filter((p) => p >= 1 && p <= last))
  const sorted = [...pages].sort((a, b) => a - b)

  const items: PageItem[] = []
  sorted.forEach((page, i) => {
    const previous = sorted[i - 1]
    if (previous !== undefined && page - previous === 2) {
      items.push(previous + 1)
    } else if (previous !== undefined && page - previous > 2) {
      items.push('gap')
    }
    items.push(page)
  })
  return items
}

/** "Showing 13–24 of 40": the range of items on this page. */
export function pageRange(page: number, perPage: number, total: number): { from: number; to: number } {
  if (total === 0) {
    return { from: 0, to: 0 }
  }
  const from = (page - 1) * perPage + 1
  return { from, to: Math.min(page * perPage, total) }
}
