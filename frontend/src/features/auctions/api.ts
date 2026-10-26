import { api } from '../../lib/api'
import type { Auction, AuctionStatus, AutoBid, AutoBidResponse, BidHistoryRow, CursorPage, Paginated, PlaceBidResponse, Single } from '../../types/api'

export const auctionKeys = {
  all: ['auctions'] as const,
  list: (filter: AuctionListFilter, page: number) => ['auctions', 'list', filter, page] as const,
  detail: (id: number) => ['auctions', 'detail', id] as const,
  bids: (id: number) => ['auctions', 'bids', id] as const,
  autoBid: (id: number) => ['auctions', 'auto-bid', id] as const,
}

/** A status tab, every auction, or the signed-in bidder's watchlist. */
export type AuctionListFilter = AuctionStatus | 'ALL' | 'WATCHING'

export async function fetchAuctions(filter: AuctionListFilter, page: number) {
  const status = filter === 'ALL' || filter === 'WATCHING' ? undefined : filter
  const { data } = await api.get<Paginated<Auction>>('/api/auctions', {
    params: { status, watching: filter === 'WATCHING' ? 1 : undefined, page, per_page: 12 },
  })
  return data
}

export async function watchAuction(id: number) {
  await api.put(`/api/auctions/${id}/watch`)
}

export async function unwatchAuction(id: number) {
  await api.delete(`/api/auctions/${id}/watch`)
}

export async function fetchAuction(id: number) {
  const { data } = await api.get<Single<Auction>>(`/api/auctions/${id}`)
  return data.data
}

export async function fetchBidHistory(id: number) {
  const { data } = await api.get<CursorPage<BidHistoryRow>>(`/api/auctions/${id}/bids`, { params: { per_page: 50 } })
  return data
}

export async function fetchMyAutoBid(id: number) {
  const { data } = await api.get<{ data: AutoBid | null }>(`/api/auctions/${id}/auto-bid`)
  return data.data
}

export async function placeBid(auctionId: number, amount: number, idempotencyKey: string) {
  const { data } = await api.post<PlaceBidResponse>(
    `/api/auctions/${auctionId}/bids`,
    { amount },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}

export async function setAutoBid(auctionId: number, maxAmount: number, idempotencyKey: string) {
  const { data } = await api.put<AutoBidResponse>(
    `/api/auctions/${auctionId}/auto-bid`,
    { max_amount: maxAmount },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}
