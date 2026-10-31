import { describe, expect, it } from 'vitest'
import { emptyHistoryText, reserveBadge } from '../statusText'

describe('emptyHistoryText', () => {
  it('invites a first bid only while the auction is live', () => {
    expect(emptyHistoryText('LIVE')).toBe('No bids yet. Be the first!')
  })

  it.each([
    ['SCHEDULED', 'Bidding opens when the auction starts.'],
    ['COMPLETED', 'No bids were placed.'],
    ['CANCELLED', 'The auction was cancelled before any bids were placed.'],
  ] as const)('says something fitting for %s', (status, text) => {
    expect(emptyHistoryText(status)).toBe(text)
  })
})

describe('reserveBadge', () => {
  it('shows the reserve state while live or scheduled', () => {
    expect(reserveBadge({ status: 'LIVE', reserve_met: false })).toEqual({ text: 'Reserve not met', met: false })
    expect(reserveBadge({ status: 'LIVE', reserve_met: true })).toEqual({ text: 'Reserve met', met: true })
    expect(reserveBadge({ status: 'SCHEDULED', reserve_met: false })).toEqual({ text: 'Reserve not met', met: false })
  })

  it('is hidden once the auction is closed or cancelled', () => {
    expect(reserveBadge({ status: 'COMPLETED', reserve_met: false })).toBeNull()
    expect(reserveBadge({ status: 'CANCELLED', reserve_met: false })).toBeNull()
  })
})
