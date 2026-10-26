import { api } from '../../lib/api'
import type { AuditUsers } from './auditFormat'
import type { AdminAuction, AdminBidRow, AuctionEvent, AuctionStatistics, AuctionStatus, BidFlag, CursorPage, FlagStatus, MonitorSnapshot, Paginated, Single } from '../../types/api'

export const adminKeys = {
  all: ['admin'] as const,
  list: (status: AuctionStatus | 'ALL', page: number) => ['admin', 'auctions', status, page] as const,
  detail: (id: number) => ['admin', 'auction', id] as const,
  events: (id: number, type: string) => ['admin', 'events', id, type] as const,
  bids: (id: number) => ['admin', 'bids', id] as const,
  statistics: ['admin', 'statistics'] as const,
  monitor: ['admin', 'monitor'] as const,
  flags: (status: FlagStatus | 'ALL') => ['admin', 'flags', status] as const,
}

export interface AuctionInput {
  title: string
  description: string
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

export async function fetchAuctionEvents(id: number, type: string) {
  const { data } = await api.get<Paginated<AuctionEvent> & { meta: { users: AuditUsers } }>(`/api/admin/auctions/${id}/events`, {
    params: { per_page: 200, type: type === 'ALL' ? undefined : type },
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

export async function fetchFlags(status: FlagStatus | 'ALL') {
  const { data } = await api.get<Paginated<BidFlag> & { meta: { open_count: number } }>('/api/admin/flags', {
    params: { status: status === 'ALL' ? undefined : status, per_page: 100 },
  })
  return data
}

export async function reviewFlag(id: number) {
  const { data } = await api.post<Single<BidFlag>>(`/api/admin/flags/${id}/review`)
  return data.data
}

export async function fetchAdminBids(id: number) {
  const { data } = await api.get<CursorPage<AdminBidRow>>(`/api/admin/auctions/${id}/bids`, { params: { per_page: 100 } })
  return data
}
