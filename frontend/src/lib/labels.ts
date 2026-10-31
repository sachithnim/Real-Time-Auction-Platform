import type { AuctionResult } from '../types/api'

export const resultLabels: Record<AuctionResult, string> = {
  SOLD: 'Sold',
  RESERVE_NOT_MET: 'Reserve not met',
  NO_BIDS: 'No bids',
}

/** "0 bids", "1 bid", "8 bids". */
export function bidCount(count: number | undefined): string {
  const n = count ?? 0
  return n === 1 ? '1 bid' : `${n} bids`
}
