import type { AuctionResult } from '../types/api'

export const resultLabels: Record<AuctionResult, string> = {
  SOLD: 'Sold',
  RESERVE_NOT_MET: 'Reserve not met',
  NO_BIDS: 'No bids',
}
