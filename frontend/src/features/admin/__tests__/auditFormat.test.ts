import { describe, expect, it } from 'vitest'
import type { AuctionEvent } from '../../../types/api'
import { describeEvent, eventTitle, fieldValue } from '../auditFormat'

const event = (type: string, payload: Record<string, unknown>): AuctionEvent => ({ id: 1, type, actor: null, payload, created_at: '2030-01-01T10:00:00.000000Z' })
const users = { '2': { name: 'Bidder 1', email: 'bidder1@auction.test' }, '3': { name: 'Bidder 2', email: 'bidder2@auction.test' } }

describe('audit log wording', () => {
  it('turns event codes into readable titles', () => {
    expect(eventTitle('AUCTION_UPDATED')).toBe('Auction edited')
    expect(eventTitle('AUTO_BID_MAX_REACHED')).toBe('Maximum reached')
    expect(eventTitle('SOMETHING_NEW')).toBe('Something new')
  })

  it('shows an edit as labelled "old → new" lines', () => {
    const view = describeEvent(event('AUCTION_UPDATED', { changes: { title: { from: 'Antique Brass Telescope', to: 'Antique Brass Telescope 1' } } }))

    expect(view.title).toBe('Auction edited')
    expect(view.summary).toBe('Changed title.')
    expect(view.details).toEqual([{ label: 'Title', value: 'Antique Brass Telescope → Antique Brass Telescope 1' }])
  })

  it('formats money, durations and missing values in edits', () => {
    const view = describeEvent(event('AUCTION_UPDATED', {
      changes: { starting_price: { from: 15000, to: 20000 }, anti_snipe_seconds: { from: 120, to: 90 }, reserve_price: { from: null, to: 30000 } },
    }))

    expect(view.details).toEqual([
      { label: 'Starting price', value: 'Rs. 15,000 → Rs. 20,000' },
      { label: 'Anti-sniping window', value: '2 min → 90 s' },
      { label: 'Reserve price', value: 'none → Rs. 30,000' },
    ])
  })

  it('describes a cancellation with its reason and previous status', () => {
    const view = describeEvent(event('AUCTION_CANCELLED', { reason: 'invalid details', leader_id: null, from_status: 'DRAFT', current_price: null }))

    expect(view.title).toBe('Auction cancelled')
    expect(view.summary).toBe('Cancelled while it was a draft. Reason: "invalid details".')
    expect(view.details).toEqual([])
  })

  it('names bidders instead of showing ids', () => {
    expect(describeEvent(event('LEADER_CHANGED', { previous_leader_id: 2, new_leader_id: 3, price: 20500 }), users).summary)
      .toBe('Lead passed from Bidder 1 to Bidder 2 at Rs. 20,500.')
    expect(describeEvent(event('LEADER_CHANGED', { previous_leader_id: null, new_leader_id: 9, price: 20000 }), users).summary)
      .toBe('Lead passed from nobody to User #9 at Rs. 20,000.')
  })

  it('explains rejected bids', () => {
    const view = describeEvent(event('BID_REJECTED', { action: 'BID', bidder_id: 3, amount: 25000, reason: 'BID_TOO_LOW', details: { min_next_bid: 25500 } }), users)

    expect(view.summary).toBe('Bid of Rs. 25,000 by Bidder 2 was rejected: below the minimum bid (minimum was Rs. 25,500).')
  })

  it('describes bids, automatic bids and the outcome', () => {
    expect(describeEvent(event('AUTO_BID_PLACED', { bidder_id: 2, amount: 15500, previous_price: 15000, new_price: 15500 }), users).summary)
      .toBe('Automatic bid of Rs. 15,500 for Bidder 1. Price Rs. 15,000 → Rs. 15,500.')
    expect(describeEvent(event('RESERVE_NOT_MET', { highest_bid: 47000, reserve_price: 50000 })).summary)
      .toBe('Highest bid Rs. 47,000 was below the reserve of Rs. 50,000, so the item was not sold.')
    expect(describeEvent(event('WINNER_SELECTED', { winner_id: 2, final_price: 15500, reserve_price: null }), users).summary)
      .toBe('Bidder 1 won at Rs. 15,500.')
  })

  it('never shows raw codes or underscores in the creation details', () => {
    const view = describeEvent(event('AUCTION_CREATED', {
      title: 'Lamp', description: 'x', starting_price: 1000, reserve_price: null, min_increment: 100,
      start_at: '2030-01-01T10:00:00Z', end_at: '2030-01-01T11:00:00Z', anti_snipe_seconds: 120, extension_seconds: 60, max_extensions: 2,
    }))

    for (const line of view.details) {
      expect(line.label).not.toMatch(/_/)
    }
    expect(view.details).toContainEqual({ label: 'Reserve price', value: 'none' })
    expect(view.details).toContainEqual({ label: 'Extension', value: '1 min' })
  })

  it('formats field values by meaning', () => {
    expect(fieldValue('current_price', 25500)).toBe('Rs. 25,500')
    expect(fieldValue('max_extensions', 3)).toBe('3')
    expect(fieldValue('extension_seconds', 0)).toBe('off')
  })
})
