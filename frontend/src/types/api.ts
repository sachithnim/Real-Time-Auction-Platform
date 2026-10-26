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
  /** The viewer's own watchlist status (always false for guests). */
  is_watching: boolean
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

export interface TopBidder {
  id: number
  name: string
  email: string
  bids: number
  auctions: number
  wins: number
}

/** GET /api/admin/statistics. Null means there is nothing to measure yet. */
export interface AuctionStatistics {
  auctions: { total: number; by_status: Record<AuctionStatus, number> }
  results: { completed: number; sold: number; reserve_not_met: number; no_bids: number; sell_through_percent: number | null }
  revenue: { total: number; average: number | null; highest: number | null }
  bids: { total: number; manual: number; auto: number; bidders: number; auctions_with_bids: number; average_per_auction: number | null }
  anti_sniping: { extended_auctions: number; total_extensions: number }
  top_bidders: TopBidder[]
}

/** One running auction on the admin live monitor (admin-only data). */
export interface MonitorAuction {
  id: number
  title: string
  status: AuctionStatus
  starting_price: number
  current_price: number | null
  reserve_price: number | null
  reserve_met: boolean
  min_next_bid: number | null
  leader: { id: number; name: string; email: string; pseudonym: string | null } | null
  bids_count: number
  recent_bids: number
  active_auto_bids: number
  end_at: string
  original_end_at: string
  extensions_count: number
  max_extensions: number
  anti_snipe_seconds: number
  sequence: number
}

/** A monitor update for an auction that no longer exists. */
export interface MonitorAuctionGone {
  id: number
  status: null
}

export interface MonitorRejection {
  id: number
  auction_id: number
  auction_title: string | null
  bidder: { id: number; name: string; email: string } | null
  action: 'BID' | 'AUTO_BID'
  amount: number | null
  reason: string | null
  message: string | null
  created_at: string
}

/** GET /api/admin/monitor */
export interface MonitorSnapshot {
  auctions: MonitorAuction[]
  recent_rejections: MonitorRejection[]
}

export type FlagRule = 'RAPID_BIDDING' | 'MANY_REJECTIONS' | 'SHARED_IP' | 'ALTERNATING_LEADERS'
export type FlagStatus = 'OPEN' | 'REVIEWED'

/** A suspicious bidding pattern for admin review (admin-only data). */
export interface BidFlag {
  id: number
  rule: FlagRule
  status: FlagStatus
  auction: { id: number; title: string | null }
  bidders: { id: number; name: string; email: string }[]
  details: Record<string, unknown>
  occurrences: number
  first_detected_at: string
  last_detected_at: string
  reviewed_by: { id: number; name: string } | null
  reviewed_at: string | null
}

export interface PlaceBidResponse {
  data: { bid: { id: number; amount: number; type: BidType; created_at: string }; auction: Auction }
  meta: Meta
}

export interface AutoBidResponse {
  data: { auto_bid: AutoBid; auction: Auction }
  meta: Meta
}
