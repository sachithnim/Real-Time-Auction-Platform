import { api } from '../../lib/api'
import type { Auction, AuctionStatus, AutoBid, AutoBidResponse, BidHistoryRow, Category, CursorPage, Paginated, Payment, PlaceBidResponse, Single } from '../../types/api'

export const auctionKeys = {
  all: ['auctions'] as const,
  list: (filter: AuctionListFilter, category: string | null, page: number) => ['auctions', 'list', filter, category ?? 'ALL', page] as const,
  categories: ['categories'] as const,
  payment: (id: number) => ['auctions', 'payment', id] as const,
  detail: (id: number) => ['auctions', 'detail', id] as const,
  bids: (id: number) => ['auctions', 'bids', id] as const,
  autoBid: (id: number) => ['auctions', 'auto-bid', id] as const,
}

/** A status tab, every auction, or the signed-in bidder's watchlist. */
export type AuctionListFilter = AuctionStatus | 'ALL' | 'WATCHING'

/** category is a category slug, or null for every category. */
export async function fetchAuctions(filter: AuctionListFilter, page: number, category: string | null = null) {
  const status = filter === 'ALL' || filter === 'WATCHING' ? undefined : filter
  const { data } = await api.get<Paginated<Auction>>('/api/auctions', {
    params: { status, watching: filter === 'WATCHING' ? 1 : undefined, category: category ?? undefined, page, per_page: 12 },
  })
  return data
}

export async function fetchCategories() {
  const { data } = await api.get<{ data: Category[] }>('/api/categories')
  return data.data
}

export interface CardInput {
  cardholder_name: string
  card_number: string
  expiry: string
  cvc: string
}

/** The signed-in winner's payment for a sold auction. */
export async function fetchMyPayment(auctionId: number) {
  const { data } = await api.get<Single<Payment>>(`/api/auctions/${auctionId}/payment`)
  return data.data
}

/** One checkout attempt. A declined card comes back as a FAILED payment, not an error. */
export async function payForAuction(auctionId: number, card: CardInput, idempotencyKey: string) {
  const { data } = await api.post<Single<Payment>>(`/api/auctions/${auctionId}/payment`, card, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })
  return data.data
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
