import { describe, expect, it } from 'vitest'
import type { BidFlag } from '../../../types/api'
import { describeFlag } from '../flagFormat'

function flag(rule: BidFlag['rule'], details: Record<string, unknown>): BidFlag {
  return {
    id: 1,
    rule,
    status: 'OPEN',
    auction: { id: 4, title: 'Kandyan Mask' },
    bidders: [],
    details,
    occurrences: 1,
    first_detected_at: '2026-10-27T10:00:00Z',
    last_detected_at: '2026-10-27T10:00:00Z',
    reviewed_by: null,
    reviewed_at: null,
  }
}

describe('describeFlag', () => {
  it('describes rapid bidding with its count and window', () => {
    const d = describeFlag(flag('RAPID_BIDDING', { bids: 12, window_seconds: 60 }))
    expect(d.title).toBe('Rapid bidding')
    expect(d.evidence).toBe('12 manual bids within 60 seconds')
  })

  it('lists the reasons behind many rejections in plain words', () => {
    const d = describeFlag(flag('MANY_REJECTIONS', { rejections: 5, window_minutes: 5, reasons: { BID_TOO_LOW: 3, AUCTION_ENDED: 2 } }))
    expect(d.title).toBe('Many rejected bids')
    expect(d.evidence).toBe('5 rejected bids within 5 minutes (Below the minimum ×3, After the end ×2)')
  })

  it('shows the shared IP address', () => {
    expect(describeFlag(flag('SHARED_IP', { ip_addresses: ['203.0.113.7'] })).evidence).toBe('Same IP: 203.0.113.7')
  })

  it('counts lead changes between alternating leaders', () => {
    const d = describeFlag(flag('ALTERNATING_LEADERS', { lead_changes: 8, bidders: 2 }))
    expect(d.title).toBe('Alternating leaders')
    expect(d.evidence).toBe('8 lead changes between 2 bidders')
  })
})
