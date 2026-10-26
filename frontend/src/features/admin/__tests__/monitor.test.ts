import { describe, expect, it } from 'vitest'
import type { MonitorAuction, MonitorRejection, MonitorSnapshot } from '../../../types/api'
import { addMonitorRejection, applyMonitorAuction, rejectionReason } from '../monitor'

function row(id: number, overrides: Partial<MonitorAuction> = {}): MonitorAuction {
  return {
    id,
    title: `Auction ${id}`,
    status: 'LIVE',
    starting_price: 10_000,
    current_price: null,
    reserve_price: null,
    reserve_met: true,
    min_next_bid: 10_000,
    leader: null,
    bids_count: 0,
    recent_bids: 0,
    active_auto_bids: 0,
    end_at: '2026-10-26T12:00:00Z',
    original_end_at: '2026-10-26T12:00:00Z',
    extensions_count: 0,
    max_extensions: 3,
    anti_snipe_seconds: 120,
    sequence: 1,
    ...overrides,
  }
}

function rejection(id: number): MonitorRejection {
  return {
    id,
    auction_id: 1,
    auction_title: 'Auction 1',
    bidder: { id: 9, name: 'Nimal', email: 'nimal@example.com' },
    action: 'BID',
    amount: 100,
    reason: 'BID_TOO_LOW',
    message: null,
    created_at: '2026-10-26T11:00:00.000Z',
  }
}

const snapshot = (auctions: MonitorAuction[], rejections: MonitorRejection[] = []): MonitorSnapshot => ({
  auctions,
  recent_rejections: rejections,
})

describe('applyMonitorAuction', () => {
  it('replaces a row with a newer copy', () => {
    const next = applyMonitorAuction(snapshot([row(1)]), row(1, { sequence: 3, current_price: 12_000 }))

    expect(next.auctions).toEqual([row(1, { sequence: 3, current_price: 12_000 })])
  })

  it('ignores an older copy of a row', () => {
    const current = snapshot([row(1, { sequence: 5, current_price: 15_000 })])

    expect(applyMonitorAuction(current, row(1, { sequence: 4, current_price: 14_000 }))).toBe(current)
  })

  it('adds a newly started auction in end-time order', () => {
    const current = snapshot([row(1, { end_at: '2026-10-26T12:00:00Z' }), row(2, { end_at: '2026-10-26T14:00:00Z' })])

    const next = applyMonitorAuction(current, row(3, { end_at: '2026-10-26T13:00:00Z' }))

    expect(next.auctions.map((auction) => auction.id)).toEqual([1, 3, 2])
  })

  it('re-sorts when an extension moves the end time', () => {
    const current = snapshot([row(1, { end_at: '2026-10-26T12:00:00Z' }), row(2, { end_at: '2026-10-26T12:01:00Z' })])

    const next = applyMonitorAuction(current, row(1, { sequence: 2, end_at: '2026-10-26T12:02:00Z' }))

    expect(next.auctions.map((auction) => auction.id)).toEqual([2, 1])
  })

  it('drops an auction that ended, was cancelled or no longer exists', () => {
    const current = snapshot([row(1), row(2), row(3)])

    let next = applyMonitorAuction(current, row(1, { status: 'COMPLETED', sequence: 9 }))
    next = applyMonitorAuction(next, row(2, { status: 'CANCELLED', sequence: 9 }))
    next = applyMonitorAuction(next, { id: 3, status: null })

    expect(next.auctions).toEqual([])
  })

  it('leaves the list untouched for a finished auction it never showed', () => {
    const current = snapshot([row(1)])

    expect(applyMonitorAuction(current, row(7, { status: 'COMPLETED' }))).toBe(current)
  })
})

describe('addMonitorRejection', () => {
  it('puts the newest rejection first and keeps ten', () => {
    const current = snapshot([], Array.from({ length: 10 }, (_, i) => rejection(10 - i)))

    const next = addMonitorRejection(current, rejection(11))

    expect(next.recent_rejections.map((r) => r.id)).toEqual([11, 10, 9, 8, 7, 6, 5, 4, 3, 2])
  })

  it('ignores a rejection it already shows', () => {
    const current = snapshot([], [rejection(4)])

    expect(addMonitorRejection(current, rejection(4))).toBe(current)
  })
})

describe('rejectionReason', () => {
  it('names known reasons and falls back to the code', () => {
    expect(rejectionReason('BID_TOO_LOW')).toBe('Below the minimum')
    expect(rejectionReason('SOMETHING_NEW')).toBe('SOMETHING_NEW')
    expect(rejectionReason(null)).toBe('Unknown')
  })
})
