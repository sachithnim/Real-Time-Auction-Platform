import type { Auction, AuctionResult, AuctionStatus, BidHistoryRow, BidType } from '../../types/api'

/** Events on the public auction.{id} channel (see AuctionBroadcaster on the server). */
export const AUCTION_EVENTS = [
  'BidPlaced',
  'PriceChanged',
  'LeaderChanged',
  'AuctionExtended',
  'AuctionStarted',
  'AuctionEnded',
  'AuctionCancelled',
] as const

export type AuctionEventName = (typeof AUCTION_EVENTS)[number]

export interface AuctionEventPayload {
  auction_id: number
  sequence: number
  server_time: string
  [key: string]: unknown
}

export type ApplyResult =
  | { kind: 'applied'; auction: Auction; newBid?: BidHistoryRow }
  | { kind: 'stale' }
  | { kind: 'gap' }

/** "You" when the pseudonym is the viewer's own. */
function displayName(pseudonym: string | null, auction: Auction): string | null {
  return pseudonym !== null && pseudonym === auction.my_pseudonym ? 'You' : pseudonym
}

/**
 * Applies one live event to the auction we show. Sequence numbers are
 * gap-free per auction: an old event is ignored, and a skipped number means an
 * event was missed, so the caller must re-fetch the auction.
 */
export function applyAuctionEvent(auction: Auction, name: AuctionEventName, payload: AuctionEventPayload): ApplyResult {
  if (payload.sequence <= auction.sequence) {
    return { kind: 'stale' }
  }
  if (payload.sequence > auction.sequence + 1) {
    return { kind: 'gap' }
  }

  const next: Auction = { ...auction, sequence: payload.sequence }
  const p = payload as Record<string, unknown>

  switch (name) {
    case 'BidPlaced': {
      const bid = p.bid as { id: number; amount: number; type: BidType; bidder: string; created_at: string }
      Object.assign(next, priceFields(p, auction))
      const name = displayName(bid.bidder, auction) ?? bid.bidder
      return {
        kind: 'applied',
        auction: next,
        newBid: { id: bid.id, amount: bid.amount, type: bid.type, bidder: name, is_you: name === 'You', created_at: bid.created_at },
      }
    }
    case 'PriceChanged':
      Object.assign(next, priceFields(p, auction))
      break
    case 'LeaderChanged': {
      const leader = displayName(p.leader as string | null, auction)
      Object.assign(next, { leader, is_leading: leader === 'You', current_price: p.current_price as number })
      break
    }
    case 'AuctionExtended':
      Object.assign(next, { end_at: p.end_at as string, extensions_count: p.extensions_count as number })
      break
    case 'AuctionStarted':
      Object.assign(next, {
        status: p.status as AuctionStatus,
        start_at: p.start_at as string,
        end_at: p.end_at as string,
        min_next_bid: p.min_next_bid as number,
        started_at: payload.server_time,
      })
      break
    case 'AuctionEnded': {
      const winner = displayName(p.winner as string | null, auction)
      Object.assign(next, {
        status: p.status as AuctionStatus,
        result: p.result as AuctionResult,
        final_price: p.final_price as number | null,
        winner,
        is_winner: winner === 'You',
        min_next_bid: null,
        ended_at: p.ended_at as string,
      })
      break
    }
    case 'AuctionCancelled':
      Object.assign(next, { status: p.status as AuctionStatus, cancelled_at: p.cancelled_at as string, min_next_bid: null })
      break
  }

  return { kind: 'applied', auction: next }
}

function priceFields(p: Record<string, unknown>, auction: Auction): Partial<Auction> {
  const fields: Partial<Auction> = {
    current_price: p.current_price as number,
    min_next_bid: p.min_next_bid as number,
    reserve_met: p.reserve_met as boolean,
    bids_count: p.bids_count as number,
  }
  // The leader comes with the price, so the badge never lags behind it. Older
  // events without it leave the leader to LeaderChanged, as before.
  if ('leader' in p) {
    const leader = displayName(p.leader as string | null, auction)
    Object.assign(fields, { leader, is_leading: leader === 'You' })
  }
  return fields
}
