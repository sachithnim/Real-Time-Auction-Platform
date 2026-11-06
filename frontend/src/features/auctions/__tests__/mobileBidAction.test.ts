import { describe, expect, it } from 'vitest'
import type { Auction, User } from '../../../types/api'
import { mobileBidAction } from '../mobileBidAction'

const bidder: User = { id: 5, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER', email_verified: true }
const live = { status: 'LIVE', starting_price: 20_000, current_price: 25_000, min_next_bid: 25_500, is_leading: false, my_pseudonym: null } as unknown as Auction

describe('mobileBidAction', () => {
  it('guests are asked to log in and admins get no button', () => {
    expect(mobileBidAction(live, null)).toEqual({ kind: 'login', label: 'Log in to bid', status: null })
    expect(mobileBidAction(live, { ...bidder, role: 'ADMIN' }).kind).toBe('none')
  })

  it('offers the minimum next bid, and says when the bidder was outbid', () => {
    expect(mobileBidAction(live, bidder)).toEqual({ kind: 'scroll', label: 'Bid Rs. 25,500', status: null })
    expect(mobileBidAction({ ...live, my_pseudonym: 'Bidder 2' }, bidder).status).toBe('outbid')
  })

  it('a leader can raise their maximum instead', () => {
    expect(mobileBidAction({ ...live, is_leading: true, my_pseudonym: 'You' }, bidder)).toEqual({ kind: 'scroll', label: 'Raise maximum', status: 'leading' })
  })

  it('an unconfirmed email comes first', () => {
    expect(mobileBidAction(live, { ...bidder, email_verified: false }).label).toBe('Confirm email')
  })
})
