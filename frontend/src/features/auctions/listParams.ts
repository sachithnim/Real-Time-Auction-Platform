import type { AuctionListFilter } from './api'

/** How the list is ordered. "default" is the server's own order: live, then upcoming, then finished. */
export type AuctionSort = 'default' | 'ending_soon' | 'newest' | 'price_high' | 'price_low'

export const sortOptions: { value: AuctionSort; label: string }[] = [
  { value: 'default', label: 'Recommended' },
  { value: 'ending_soon', label: 'Ending soonest' },
  { value: 'newest', label: 'Newest' },
  { value: 'price_high', label: 'Price: high to low' },
  { value: 'price_low', label: 'Price: low to high' },
]

export interface ListParams {
  status: AuctionListFilter
  category: string | null
  search: string
  sort: AuctionSort
  page: number
}

const filters: AuctionListFilter[] = ['ALL', 'LIVE', 'SCHEDULED', 'COMPLETED', 'WATCHING']
const sorts = sortOptions.map((option) => option.value)

/** The server accepts up to 100 characters. */
export const SEARCH_MAX = 100

/**
 * The list filters live in the URL (?status=live&q=brass&sort=newest&page=2), so going back
 * from an auction, reloading or sharing the link shows the same view. Bad values fall back to defaults.
 */
export function readListParams(search: URLSearchParams): ListParams {
  const status = search.get('status')?.toUpperCase() as AuctionListFilter | undefined
  const sort = search.get('sort') as AuctionSort | null
  const page = Number(search.get('page'))
  return {
    status: status && filters.includes(status) ? status : 'ALL',
    category: search.get('category') || null,
    search: (search.get('q') ?? '').slice(0, SEARCH_MAX),
    sort: sort && sorts.includes(sort) ? sort : 'default',
    page: Number.isInteger(page) && page > 1 ? page : 1,
  }
}

/** The query string for some filters; defaults are left out to keep the URL short. */
export function writeListParams({ status, category, search, sort, page }: ListParams): URLSearchParams {
  const params = new URLSearchParams()
  if (status !== 'ALL') params.set('status', status.toLowerCase())
  if (category) params.set('category', category)
  if (search.trim()) params.set('q', search.trim())
  if (sort !== 'default') params.set('sort', sort)
  if (page > 1) params.set('page', String(page))
  return params
}
