import type { AuctionListFilter } from './api'

export interface ListParams {
  status: AuctionListFilter
  category: string | null
  page: number
}

const filters: AuctionListFilter[] = ['ALL', 'LIVE', 'SCHEDULED', 'COMPLETED', 'WATCHING']

/**
 * The list filters live in the URL (?status=LIVE&category=art&page=2), so going back
 * from an auction, reloading or sharing the link shows the same view. Bad values fall back to defaults.
 */
export function readListParams(search: URLSearchParams): ListParams {
  const status = search.get('status')?.toUpperCase() as AuctionListFilter | undefined
  const page = Number(search.get('page'))
  return {
    status: status && filters.includes(status) ? status : 'ALL',
    category: search.get('category') || null,
    page: Number.isInteger(page) && page > 1 ? page : 1,
  }
}

/** The query string for some filters; defaults are left out to keep the URL short. */
export function writeListParams({ status, category, page }: ListParams): URLSearchParams {
  const search = new URLSearchParams()
  if (status !== 'ALL') search.set('status', status.toLowerCase())
  if (category) search.set('category', category)
  if (page > 1) search.set('page', String(page))
  return search
}
