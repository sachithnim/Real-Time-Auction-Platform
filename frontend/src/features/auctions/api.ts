import { api } from '../../lib/api'
import type { Auction, AuctionStatus, AutoBid, AutoBidResponse, BidHistoryRow, CursorPage, Paginated, PlaceBidResponse, Single } from '../../types/api'

export const auctionKeys = {
  all: ['auctions'] as const,
  list: (status: AuctionStatus | 'ALL', page: number) => ['auctions', 'list', status, page] as const,
  detail: (id: number) => ['auctions', 'detail', id] as const,
  bids: (id: number) => ['auctions', 'bids', id] as const,
  autoBid: (id: number) => ['auctions', 'auto-bid', id] as const,
}

export async function fetchAuctions(status: AuctionStatus | 'ALL', page: number) {
  const { data } = await api.get<Paginated<Auction>>('/api/auctions', {
    params: { status: status === 'ALL' ? undefined : status, page, per_page: 12 },
  })
  return data
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
