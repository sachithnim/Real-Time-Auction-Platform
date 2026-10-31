import type { Auction, AuctionStatus } from '../../types/api'

/** What the bid history says when there are no bids, which depends on whether bidding is still possible. */
export function emptyHistoryText(status: AuctionStatus): string {
  switch (status) {
    case 'LIVE':
      return 'No bids yet. Be the first!'
    case 'SCHEDULED':
    case 'DRAFT':
      return 'Bidding opens when the auction starts.'
    case 'COMPLETED':
      return 'No bids were placed.'
    case 'CANCELLED':
      return 'The auction was cancelled before any bids were placed.'
  }
}

/**
 * The reserve badge under the price. Only useful while bidding is open (or about to be):
 * once closed, the result panel says whether it sold, and a cancelled auction has no outcome.
 */
export function reserveBadge(auction: Pick<Auction, 'status' | 'reserve_met'>): { text: string; met: boolean } | null {
  if (auction.status !== 'LIVE' && auction.status !== 'SCHEDULED') {
    return null
  }
  return auction.reserve_met ? { text: 'Reserve met', met: true } : { text: 'Reserve not met', met: false }
}
