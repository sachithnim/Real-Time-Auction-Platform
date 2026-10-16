import { describe, expect, it } from 'vitest'
import type { Auction } from '../../../types/api'
import { applyAuctionEvent } from '../auctionEvents'

const base: Auction = {
  id: 7, title: 'Tea chest', description: '', status: 'LIVE', result: null,
  starting_price: 10_000, current_price: 10_000, final_price: null, min_increment: 500, min_next_bid: 10_500,
  leader: 'Bidder 1', is_leading: false, my_pseudonym: 'Bidder 2', winner: null, is_winner: false, reserve_met: true,
  bids_count: 1, start_at: '2030-01-01T10:00:00Z', end_at: '2030-01-01T12:00:00Z', original_end_at: '2030-01-01T12:00:00Z',
  anti_snipe_seconds: 120, extension_seconds: 120, max_extensions: 3, extensions_count: 0,
  started_at: null, ended_at: null, cancelled_at: null, sequence: 3,
}

const event = (sequence: number, extra: Record<string, unknown> = {}) => ({ auction_id: 7, sequence, server_time: '2030-01-01T11:00:00.000Z', ...extra })

describe('applyAuctionEvent', () => {
  it('ignores events already included in the snapshot', () => {
    expect(applyAuctionEvent(base, 'PriceChanged', event(3)).kind).toBe('stale')
    expect(applyAuctionEvent(base, 'PriceChanged', event(1)).kind).toBe('stale')
  })

  it('reports a gap when an event was missed', () => {
    expect(applyAuctionEvent(base, 'PriceChanged', event(5)).kind).toBe('gap')
  })

  it('applies a new bid and shows the viewer as "You"', () => {
    const result = applyAuctionEvent(base, 'BidPlaced', event(4, {
      bid: { id: 9, amount: 11_000, type: 'MANUAL', bidder: 'Bidder 2', created_at: '2030-01-01T11:00:00Z' },
      current_price: 11_000, min_next_bid: 11_500, reserve_met: true, bids_count: 2,
    }))

    expect(result.kind).toBe('applied')
    if (result.kind !== 'applied') return
    expect(result.auction).toMatchObject({ current_price: 11_000, min_next_bid: 11_500, bids_count: 2, sequence: 4 })
    expect(result.newBid).toMatchObject({ id: 9, bidder: 'You', is_you: true })
  })

  it('names other bidders by pseudonym only', () => {
    const result = applyAuctionEvent(base, 'BidPlaced', event(4, {
      bid: { id: 9, amount: 11_000, type: 'AUTO', bidder: 'Bidder 1', created_at: '2030-01-01T11:00:00Z' },
      current_price: 11_000, min_next_bid: 11_500, reserve_met: true, bids_count: 2,
    }))

    expect(result.kind === 'applied' && result.newBid).toMatchObject({ bidder: 'Bidder 1', is_you: false, type: 'AUTO' })
  })

  it('marks the viewer as leading when they take the lead', () => {
    const result = applyAuctionEvent(base, 'LeaderChanged', event(4, { leader: 'Bidder 2', current_price: 11_000 }))

    expect(result.kind === 'applied' && result.auction).toMatchObject({ leader: 'You', is_leading: true })
  })

  it('moves the end time on an extension', () => {
    const result = applyAuctionEvent(base, 'AuctionExtended', event(4, { end_at: '2030-01-01T12:02:00Z', extensions_count: 1 }))

    expect(result.kind === 'applied' && result.auction).toMatchObject({ end_at: '2030-01-01T12:02:00Z', extensions_count: 1 })
  })

  it('shows the result and whether the viewer won when the auction ends', () => {
    const result = applyAuctionEvent(base, 'AuctionEnded', event(4, {
      status: 'COMPLETED', result: 'SOLD', final_price: 11_000, winner: 'Bidder 2', ended_at: '2030-01-01T12:00:00Z',
    }))

    expect(result.kind === 'applied' && result.auction).toMatchObject({
      status: 'COMPLETED', result: 'SOLD', final_price: 11_000, winner: 'You', is_winner: true, min_next_bid: null,
    })
  })

  it('opens a scheduled auction when it starts', () => {
    const scheduled = { ...base, status: 'SCHEDULED' as const }
    const result = applyAuctionEvent(scheduled, 'AuctionStarted', event(4, {
      status: 'LIVE', start_at: base.start_at, end_at: base.end_at, min_next_bid: 10_000,
    }))

    expect(result.kind === 'applied' && result.auction.status).toBe('LIVE')
  })
})
