import { describe, expect, it } from 'vitest'
import { myBidSummary, readFilter } from '../myBids'
import { myBidAuction } from './myBidAuction'

describe('myBidSummary', () => {
  it('a leader is told their maximum still bids for them', () => {
    const summary = myBidSummary(myBidAuction({}, { max_amount: 20_000, max_active: true }))

    expect(summary.badge).toEqual({ text: 'You’re leading', tone: 'good' })
    expect(summary.note).toBe('Your maximum of Rs. 20,000 bids for you if someone outbids you.')
    expect(summary.action).toEqual({ label: 'View', primary: false })
  })

  it('an outbid bidder is offered to bid again, and told when their maximum was passed', () => {
    const passed = myBidSummary(myBidAuction({ is_leading: false }, { state: 'OUTBID', max_amount: 15_000, max_active: false }))
    expect(passed.badge.text).toBe('Outbid')
    expect(passed.note).toBe('Your maximum of Rs. 15,000 was passed.')
    expect(passed.action).toEqual({ label: 'Bid again', primary: true })

    expect(myBidSummary(myBidAuction({}, { state: 'OUTBID' })).note).toBeNull()
  })

  it('an unpaid win asks for payment, a declined one says so, a paid one offers the receipt', () => {
    const won = { status: 'COMPLETED', result: 'SOLD', is_winner: true } as const

    expect(myBidSummary(myBidAuction(won, { state: 'WON', payment_status: 'PENDING' })).action).toEqual({ label: 'Pay now', primary: true })
    expect(myBidSummary(myBidAuction(won, { state: 'WON', payment_status: 'FAILED' })).note).toMatch(/declined/)

    const paid = myBidSummary(myBidAuction(won, { state: 'WON', payment_status: 'PAID' }))
    expect(paid.badge.text).toBe('Won · Paid')
    expect(paid.action).toEqual({ label: 'View receipt', primary: false })
  })

  it('the highest bid under the reserve is explained, not just called lost', () => {
    const summary = myBidSummary(myBidAuction({ status: 'COMPLETED', result: 'RESERVE_NOT_MET', is_leading: true }, { state: 'LOST' }))

    expect(summary.badge.text).toBe('Reserve not met')
    expect(summary.note).toMatch(/highest, but below the seller’s reserve/)
  })

  it('a lost auction names the winner by pseudonym only', () => {
    const summary = myBidSummary(myBidAuction({ status: 'COMPLETED', result: 'SOLD', is_leading: false, winner: 'Bidder 3' }, { state: 'LOST' }))

    expect(summary.badge.text).toBe('Not won')
    expect(summary.note).toBe('Won by Bidder 3.')
  })

  it('a cancelled auction says no one won it', () => {
    expect(myBidSummary(myBidAuction({ status: 'CANCELLED' }, { state: 'CANCELLED' })).badge.text).toBe('Cancelled')
  })
})

describe('readFilter', () => {
  it('keeps a known tab and falls back to all for anything else', () => {
    expect(readFilter('won')).toBe('won')
    expect(readFilter('everything')).toBe('all')
    expect(readFilter(null)).toBe('all')
  })
})
