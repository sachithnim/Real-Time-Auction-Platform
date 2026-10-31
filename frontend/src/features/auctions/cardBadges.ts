import type { Auction } from '../../types/api'

export interface CardBadge {
  text: string
  tone: 'good' | 'warning' | 'urgent'
}

/** Live auctions with less than this left get an "Ending soon" badge. */
export const ENDING_SOON_MS = 10 * 60_000

/**
 * Small badges on an auction card: how the signed-in bidder stands (leading, outbid, won)
 * and whether a live auction is about to end. `now` is the server clock.
 */
export function cardBadges(
  auction: Pick<Auction, 'status' | 'result' | 'end_at' | 'is_leading' | 'my_pseudonym' | 'is_winner'>,
  now: number,
): CardBadge[] {
  const badges: CardBadge[] = []

  if (auction.status === 'LIVE') {
    if (auction.is_leading) {
      badges.push({ text: 'You’re leading', tone: 'good' })
    } else if (auction.my_pseudonym !== null) {
      // A pseudonym comes with the first bid, so having one and not leading means outbid.
      badges.push({ text: 'Outbid', tone: 'warning' })
    }
    const left = Date.parse(auction.end_at) - now
    if (left > 0 && left <= ENDING_SOON_MS) {
      badges.push({ text: 'Ending soon', tone: 'urgent' })
    }
  }

  if (auction.status === 'COMPLETED' && auction.result === 'SOLD' && auction.is_winner) {
    badges.push({ text: 'You won', tone: 'good' })
  }

  return badges
}
