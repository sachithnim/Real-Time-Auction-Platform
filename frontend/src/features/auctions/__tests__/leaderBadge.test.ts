import { describe, expect, it } from 'vitest'
import type { Auction } from '../../../types/api'
import { leaderBadge } from '../leaderBadge'

const auction = (overrides: Partial<Auction>) =>
  ({ status: 'LIVE', result: null, leader: null, is_leading: false, winner: null, is_winner: false, ...overrides }) as Auction

describe('leaderBadge', () => {
  it('shows who leads while the auction is live', () => {
    expect(leaderBadge(auction({ leader: 'You', is_leading: true }))).toEqual({ text: 'You are leading', tone: 'good' })
    expect(leaderBadge(auction({ leader: 'Bidder 2' }))).toEqual({ text: 'Leader: Bidder 2', tone: 'neutral' })
    expect(leaderBadge(auction({}))).toBeNull()
  })

  it('says "You won", never "You are leading", once a sold auction has closed', () => {
    const won = auction({ status: 'COMPLETED', result: 'SOLD', leader: 'You', is_leading: true, winner: 'You', is_winner: true })

    expect(leaderBadge(won)).toEqual({ text: 'You won', tone: 'good' })
  })

  it('names the winner to everyone else', () => {
    const sold = auction({ status: 'COMPLETED', result: 'SOLD', leader: 'Bidder 1', winner: 'Bidder 1' })

    expect(leaderBadge(sold)).toEqual({ text: 'Won by Bidder 1', tone: 'neutral' })
  })

  it('shows the highest bid, without a winner, when the reserve was not met', () => {
    const mine = auction({ status: 'COMPLETED', result: 'RESERVE_NOT_MET', leader: 'You', is_leading: true })
    const theirs = auction({ status: 'COMPLETED', result: 'RESERVE_NOT_MET', leader: 'Bidder 3' })

    expect(leaderBadge(mine)).toEqual({ text: 'Your bid was the highest', tone: 'neutral' })
    expect(leaderBadge(theirs)).toEqual({ text: 'Highest bid: Bidder 3', tone: 'neutral' })
  })

  it('shows nothing for a cancelled auction or one that ended without bids', () => {
    expect(leaderBadge(auction({ status: 'CANCELLED', leader: 'Bidder 2' }))).toBeNull()
    expect(leaderBadge(auction({ status: 'COMPLETED', result: 'NO_BIDS' }))).toBeNull()
  })
})
