import type { Auction } from '../../types/api'

/** What the big number on an auction means, which depends on how far the auction has got. */
export function priceLabel(auction: Pick<Auction, 'status' | 'result' | 'current_price'>): string {
  if (auction.current_price === null) {
    return 'Starting price'
  }
  if (auction.status === 'COMPLETED') {
    return auction.result === 'SOLD' ? 'Sold for' : 'Highest bid'
  }
  if (auction.status === 'CANCELLED') {
    return 'Last bid'
  }
  return 'Current price'
}
