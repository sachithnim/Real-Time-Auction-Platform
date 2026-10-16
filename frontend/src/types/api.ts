// Shapes returned by the Laravel API. Money is always an integer number of rupees.

export type Role = 'ADMIN' | 'BIDDER'
export type AuctionStatus = 'DRAFT' | 'SCHEDULED' | 'LIVE' | 'COMPLETED' | 'CANCELLED'
export type AuctionResult = 'SOLD' | 'RESERVE_NOT_MET' | 'NO_BIDS'
export type BidType = 'MANUAL' | 'AUTO'

export interface User {
  id: number
  name: string
  email: string
  role: Role
}

export interface Meta {
  server_time: string
}

export interface Single<T> {
  data: T
  meta: Meta
}

export interface Paginated<T> {
  data: T[]
  meta: Meta & { current_page: number; last_page: number; per_page: number; total: number }
}

export interface CursorPage<T> {
  data: T[]
  meta: Meta & { next_cursor: string | null; prev_cursor: string | null; per_page: number }
}

/** Bidder view: never contains the reserve price or anyone's identity. */
export interface Auction {
  id: number
  title: string
  description: string
  status: AuctionStatus
  result: AuctionResult | null
  starting_price: number
  current_price: number | null
  final_price: number | null
  min_increment: number
  min_next_bid: number | null
  leader: string | null
  is_leading: boolean
  my_pseudonym: string | null
  winner: string | null
  is_winner: boolean
  reserve_met: boolean
  bids_count?: number
  start_at: string
  end_at: string
  original_end_at: string
  anti_snipe_seconds: number
  extension_seconds: number
  max_extensions: number
  extensions_count: number
  started_at: string | null
  ended_at: string | null
  cancelled_at: string | null
  /** Last real-time event sequence included in this snapshot. */
  sequence: number
}

/** Admin view: includes the private reserve and ids. */
export interface AdminAuction {
  id: number
  title: string
  description: string
  status: AuctionStatus
  result: AuctionResult | null
  starting_price: number
  reserve_price: number | null
  min_increment: number
  current_price: number | null
  final_price: number | null
  start_at: string
  end_at: string
  original_end_at: string
  anti_snipe_seconds: number
  extension_seconds: number
  max_extensions: number
  extensions_count: number
  leader_id: number | null
  winner_id: number | null
  created_by: number
  bids_count: number
  started_at: string | null
  ended_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
}

export interface BidHistoryRow {
  id: number
  amount: number
  type: BidType
  /** "Bidder N" or "You". */
  bidder: string
  is_you: boolean
  created_at: string
}

export interface AdminBidRow {
  id: number
  amount: number
  type: BidType
  bidder: { id: number; name: string; email: string; pseudonym: string }
  created_at: string
}

export interface AutoBid {
  max_amount: number
  is_active: boolean
  max_set_at: string
}

export interface AuctionEvent {
  id: number
  type: string
  actor: { id: number; name: string; email: string; role: Role } | null
  payload: Record<string, unknown>
  created_at: string
}

export interface PlaceBidResponse {
  data: { bid: { id: number; amount: number; type: BidType; created_at: string }; auction: Auction }
  meta: Meta
}

export interface AutoBidResponse {
  data: { auto_bid: AutoBid; auction: Auction }
  meta: Meta
}
