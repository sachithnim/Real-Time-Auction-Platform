import { describe, expect, it } from 'vitest'
import type { Auction } from '../../../types/api'
import { cardBadges } from '../cardBadges'

const now = Date.parse('2026-10-31T12:00:00Z')
const inMinutes = (minutes: number) => new Date(now + minutes * 60_000).toISOString()

const live = {
  status: 'LIVE', result: null, end_at: inMinutes(60), is_leading: false, my_pseudonym: null, is_winner: false,
} as Pick<Auction, 'status' | 'result' | 'end_at' | 'is_leading' | 'my_pseudonym' | 'is_winner'>

const texts = (overrides: Partial<typeof live>) => cardBadges({ ...live, ...overrides }, now).map((badge) => badge.text)

describe('cardBadges', () => {
  it('shows nothing for someone who has not bid on an auction with time left', () => {
    expect(texts({})).toEqual([])
  })

  it('tells the bidder whether they lead or were outbid', () => {
    expect(texts({ is_leading: true, my_pseudonym: 'You' })).toEqual(['You’re leading'])
    expect(texts({ my_pseudonym: 'You' })).toEqual(['Outbid'])
  })

  it('flags a live auction in its last 10 minutes', () => {
    expect(texts({ end_at: inMinutes(10) })).toEqual(['Ending soon'])
    expect(texts({ end_at: inMinutes(11) })).toEqual([])
    // Past the end it is closing, not "ending soon".
    expect(texts({ end_at: inMinutes(-1) })).toEqual([])
  })

  it('combines the bidder badge with ending soon', () => {
    expect(texts({ my_pseudonym: 'You', end_at: inMinutes(3) })).toEqual(['Outbid', 'Ending soon'])
  })

  it('celebrates a win, and ignores standing once an auction is not live', () => {
    expect(texts({ status: 'COMPLETED', result: 'SOLD', is_winner: true })).toEqual(['You won'])
    expect(texts({ status: 'COMPLETED', result: 'SOLD', is_winner: false, my_pseudonym: 'You' })).toEqual([])
    expect(texts({ status: 'SCHEDULED', end_at: inMinutes(5) })).toEqual([])
  })
})
