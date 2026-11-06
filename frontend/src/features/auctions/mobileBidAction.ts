import { formatRs } from '../../lib/money'
import type { Auction, User } from '../../types/api'

export interface MobileBidAction {
  /** Log in first, go back to the bid card, or nothing (admins cannot bid). */
  kind: 'login' | 'scroll' | 'none'
  label: string
  /** How the viewer stands, when they have bid. */
  status: 'leading' | 'outbid' | null
}

/** What the phone bid bar offers, from who is looking and how they stand. */
export function mobileBidAction(auction: Auction, user: User | null): MobileBidAction {
  if (!user) {
    return { kind: 'login', label: 'Log in to bid', status: null }
  }
  if (user.role === 'ADMIN') {
    return { kind: 'none', label: '', status: null }
  }

  const status = auction.is_leading ? 'leading' : auction.my_pseudonym !== null && auction.current_price !== null ? 'outbid' : null

  if (!user.email_verified) {
    return { kind: 'scroll', label: 'Confirm email', status }
  }
  if (auction.is_leading) {
    return { kind: 'scroll', label: 'Raise maximum', status }
  }
  return { kind: 'scroll', label: `Bid ${formatRs(auction.min_next_bid ?? auction.starting_price)}`, status }
}
