import type { Auction } from '../../types/api'

export interface LeaderBadge {
  text: string
  /** "good" when it is good news for the viewer, otherwise neutral. */
  tone: 'good' | 'neutral'
}

/**
 * The badge under the price: who leads while bidding is open, and who won (or
 * held the highest bid) once it has closed. Nothing for a cancelled auction or
 * one without bids.
 */
export function leaderBadge(auction: Auction): LeaderBadge | null {
  if (auction.status === 'COMPLETED') {
    if (auction.result === 'SOLD') {
      return auction.is_winner ? { text: 'You won', tone: 'good' } : { text: `Won by ${auction.winner}`, tone: 'neutral' }
    }
    if (auction.result === 'RESERVE_NOT_MET' && auction.leader) {
      return { text: auction.is_leading ? 'Your bid was the highest' : `Highest bid: ${auction.leader}`, tone: 'neutral' }
    }
    return null
  }

  if (auction.status === 'CANCELLED' || !auction.leader) {
    return null
  }

  return auction.is_leading ? { text: 'You are leading', tone: 'good' } : { text: `Leader: ${auction.leader}`, tone: 'neutral' }
}
