import { api } from '../../lib/api'
import type { AuditUsers } from './auditFormat'
import type { AdminAuction, AdminBidder, AdminBidRow, AdminPayment, AuctionImage, AuctionEvent, AuctionStatistics, AuctionStatus, BidFlag, Category, CursorPage, FlagStatus, MonitorSnapshot, Paginated, PaymentCounts, PaymentStatus, Single } from '../../types/api'

export const adminKeys = {
  all: ['admin'] as const,
  list: (status: AuctionStatus | 'ALL', page: number) => ['admin', 'auctions', status, page] as const,
  detail: (id: number) => ['admin', 'auction', id] as const,
  events: (id: number, type: string) => ['admin', 'events', id, type] as const,
  bids: (id: number) => ['admin', 'bids', id] as const,
  statistics: ['admin', 'statistics'] as const,
  monitor: ['admin', 'monitor'] as const,
  flags: (status: FlagStatus | 'ALL', page = 1) => ['admin', 'flags', status, page] as const,
  categories: ['admin', 'categories'] as const,
  bidders: (status: BidderFilter, search: string, page: number) => ['admin', 'bidders', status, search, page] as const,
  payments: (status: PaymentStatus | 'ALL', search: string, page: number) => ['admin', 'payments', status, search, page] as const,
}

export type BidderFilter = 'all' | 'active' | 'disabled'

export async function fetchBidders(status: BidderFilter, search: string, page: number) {
  const { data } = await api.get<Paginated<AdminBidder>>('/api/admin/bidders', {
    params: { status: status === 'all' ? undefined : status, search: search.trim() || undefined, page, per_page: 20 },
  })
  return data
}

export async function disableBidder(id: number) {
  const { data } = await api.post<Single<AdminBidder>>(`/api/admin/bidders/${id}/disable`)
  return data.data
}

export async function enableBidder(id: number) {
  const { data } = await api.post<Single<AdminBidder>>(`/api/admin/bidders/${id}/enable`)
  return data.data
}

export async function fetchPayments(status: PaymentStatus | 'ALL', search: string, page: number) {
  const { data } = await api.get<Paginated<AdminPayment> & { counts: PaymentCounts }>('/api/admin/payments', {
    params: { status: status === 'ALL' ? undefined : status, search: search.trim() || undefined, page, per_page: 20 },
  })
  return data
}

/**
 * Downloads an admin CSV export. Fetched with the session like any API call (a plain
 * link would not carry the SPA's headers), then handed to the browser as a file.
 */
export async function downloadCsv(path: string, fallbackName: string) {
  const response = await api.get<Blob>(path, { responseType: 'blob' })
  const disposition = String(response.headers['content-disposition'] ?? '')
  const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName

  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export interface AuctionInput {
  title: string
  description: string
  category_id?: number | null
  starting_price: number
  reserve_price: number | null
  min_increment: number
  start_at: string
  end_at: string
  anti_snipe_seconds: number
  extension_seconds: number
  max_extensions: number
  status?: 'DRAFT' | 'SCHEDULED'
}

export async function fetchAdminAuctions(status: AuctionStatus | 'ALL', page: number) {
  const { data } = await api.get<Paginated<AdminAuction>>('/api/admin/auctions', {
    params: { status: status === 'ALL' ? undefined : status, page, per_page: 20 },
  })
  return data
}

export async function fetchAdminAuction(id: number) {
  const { data } = await api.get<Single<AdminAuction>>(`/api/admin/auctions/${id}`)
  return data.data
}

export async function createAuction(input: AuctionInput) {
  const { data } = await api.post<Single<AdminAuction>>('/api/admin/auctions', input)
  return data.data
}

export async function updateAuction(id: number, input: AuctionInput) {
  const { status: _ignored, ...body } = input
  const { data } = await api.put<Single<AdminAuction>>(`/api/admin/auctions/${id}`, body)
  return data.data
}

export async function scheduleAuction(id: number) {
  const { data } = await api.post<Single<AdminAuction>>(`/api/admin/auctions/${id}/schedule`)
  return data.data
}

export async function cancelAuction(id: number, reason: string | null) {
  const { data } = await api.post<Single<AdminAuction>>(`/api/admin/auctions/${id}/cancel`, { reason })
  return data.data
}

/** A new draft copied from an auction that did not sell. */
export async function relistAuction(id: number) {
  const { data } = await api.post<Single<AdminAuction>>(`/api/admin/auctions/${id}/relist`)
  return data.data
}

/** One page of the audit log, oldest first; "Load more" asks for the next page. */
export async function fetchAuctionEvents(id: number, type: string, page = 1) {
  const { data } = await api.get<Paginated<AuctionEvent> & { meta: { users: AuditUsers } }>(`/api/admin/auctions/${id}/events`, {
    params: { per_page: 50, page, type: type === 'ALL' ? undefined : type },
  })
  return data
}

export async function fetchStatistics() {
  const { data } = await api.get<Single<AuctionStatistics>>('/api/admin/statistics')
  return data.data
}

export async function fetchMonitor() {
  const { data } = await api.get<Single<MonitorSnapshot>>('/api/admin/monitor')
  return data.data
}

export async function fetchAdminCategories() {
  const { data } = await api.get<{ data: Category[] }>('/api/admin/categories')
  return data.data
}

export async function createCategory(name: string) {
  const { data } = await api.post<Single<Category>>('/api/admin/categories', { name })
  return data.data
}

export async function renameCategory(id: number, name: string) {
  const { data } = await api.put<Single<Category>>(`/api/admin/categories/${id}`, { name })
  return data.data
}

export async function deleteCategory(id: number) {
  await api.delete(`/api/admin/categories/${id}`)
}

export async function fetchFlags(status: FlagStatus | 'ALL', page = 1) {
  const { data } = await api.get<Paginated<BidFlag> & { meta: { open_count: number } }>('/api/admin/flags', {
    params: { status: status === 'ALL' ? undefined : status, page, per_page: 20 },
  })
  return data
}

export async function reviewFlag(id: number) {
  const { data } = await api.post<Single<BidFlag>>(`/api/admin/flags/${id}/review`)
  return data.data
}

/** Newest bids first; pass the previous page's next_cursor to get older ones. */
export async function fetchAdminBids(id: number, cursor: string | null = null) {
  const { data } = await api.get<CursorPage<AdminBidRow>>(`/api/admin/auctions/${id}/bids`, {
    params: { per_page: 50, cursor: cursor ?? undefined },
  })
  return data
}

/** Uploads photos (added after the existing ones). Uploads get a longer timeout than other calls. */
export async function uploadAuctionImages(id: number, files: File[], onProgress?: (fraction: number) => void) {
  const body = new FormData()
  files.forEach((file) => body.append('images[]', file))
  const { data } = await api.post<{ data: AuctionImage[] }>(`/api/admin/auctions/${id}/images`, body, {
    timeout: 120_000,
    onUploadProgress: (e) => onProgress?.(e.total ? e.loaded / e.total : 0),
  })
  return data.data
}

export async function deleteAuctionImage(id: number, imageId: number) {
  const { data } = await api.delete<{ data: AuctionImage[] }>(`/api/admin/auctions/${id}/images/${imageId}`)
  return data.data
}

/** `ids`: every photo of the auction in the new order; the first becomes the cover. */
export async function reorderAuctionImages(id: number, ids: number[]) {
  const { data } = await api.put<{ data: AuctionImage[] }>(`/api/admin/auctions/${id}/images/order`, { ids })
  return data.data
}
