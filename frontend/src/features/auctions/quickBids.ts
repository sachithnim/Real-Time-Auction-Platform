import type { Auction } from '../../types/api'

type Priced = Pick<Auction, 'starting_price' | 'current_price' | 'min_next_bid' | 'min_increment'>

/**
 * The step the server applies at the current price: the gap between the price
 * and the minimum next bid (it already includes the increment tier). Before the
 * first bid there is no gap yet, so the auction's own minimum increment is used.
 */
export function bidStep(auction: Priced): number {
  if (auction.current_price === null || auction.min_next_bid === null) {
    return auction.min_increment
  }
  return auction.min_next_bid - auction.current_price
}

/** One-tap amounts: the minimum next bid, then one and two steps above it. */
export function quickBidAmounts(auction: Priced): number[] {
  const minimum = auction.min_next_bid ?? auction.starting_price
  const step = Math.max(1, bidStep(auction))
  return [minimum, minimum + step, minimum + 2 * step]
}
