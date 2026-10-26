import { describe, expect, it } from 'vitest'
import { reminderToast } from '../reminders'

const base = { auction_id: 2, title: 'Signed Cricket Bat', at: '2026-10-26T18:00:00Z' }

describe('reminderToast', () => {
  it('announces an auction that is about to open', () => {
    expect(reminderToast({ ...base, kind: 'STARTING_SOON', minutes_left: 10 })).toEqual({
      title: 'Starting soon',
      body: 'Signed Cricket Bat opens for bidding in 10 minutes.',
    })
  })

  it('warns that a live auction is about to close', () => {
    expect(reminderToast({ ...base, kind: 'ENDING_SOON', minutes_left: 5 })).toEqual({
      title: 'Ending soon',
      body: 'Signed Cricket Bat closes in 5 minutes. Last chance to bid.',
    })
  })

  it('says "1 minute", not "1 minutes"', () => {
    expect(reminderToast({ ...base, kind: 'ENDING_SOON', minutes_left: 1 }).body).toBe('Signed Cricket Bat closes in 1 minute. Last chance to bid.')
  })
})
