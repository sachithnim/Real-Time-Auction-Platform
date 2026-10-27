import { describe, expect, it } from 'vitest'
import { formatDuration, formatRelative } from '../time'

describe('formatRelative', () => {
  const now = Date.parse('2026-10-28T12:00:00Z')
  const ago = (seconds: number) => new Date(now - seconds * 1000).toISOString()

  it.each([
    [0, 'just now'],
    [44, 'just now'],
    [45, '1 min ago'],
    [5 * 60, '5 min ago'],
    [59 * 60, '59 min ago'],
    [60 * 60, '1 h ago'],
    [23 * 3_600 + 59 * 60, '23 h ago'],
  ])('%s seconds ago reads "%s"', (seconds, text) => {
    expect(formatRelative(ago(seconds), now)).toBe(text)
  })

  it('shows the date once a day has passed', () => {
    const iso = ago(2 * 86_400)
    expect(formatRelative(iso, now)).toBe(new Date(iso).toLocaleDateString())
  })

  it('treats a time slightly ahead of the clock as just now', () => {
    expect(formatRelative(new Date(now + 2_000).toISOString(), now)).toBe('just now')
  })
})

describe('formatDuration', () => {
  it.each([
    [0, '0 s'],
    [30, '30 s'],
    [60, '1 min'],
    [90, '1 min 30 s'],
    [120, '2 min'],
    [600, '10 min'],
  ])('%s seconds reads "%s"', (seconds, text) => {
    expect(formatDuration(seconds)).toBe(text)
  })
})
