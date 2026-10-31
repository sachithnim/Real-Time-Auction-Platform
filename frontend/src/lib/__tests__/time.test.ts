import { describe, expect, it } from 'vitest'
import { formatDateTime, formatDuration, formatFromNow, formatRelative } from '../time'

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

describe('formatFromNow', () => {
  const now = Date.parse('2026-10-28T12:00:00Z')
  const at = (seconds: number) => new Date(now + seconds * 1000).toISOString()

  it.each([
    [30, 'in under a minute'],
    [60, 'in 1 min'],
    [59 * 60, 'in 59 min'],
    [60 * 60, 'in 1 h'],
    [2 * 3_600 + 14 * 60, 'in 2 h 14 min'],
    [24 * 3_600, 'in 1 day'],
    [3 * 86_400 + 3_600, 'in 3 days'],
  ])('%s seconds ahead reads "%s"', (seconds, text) => {
    expect(formatFromNow(at(seconds), now)).toBe(text)
  })

  it.each([
    [-30, 'just now'],
    [-5 * 60, '5 min ago'],
    [-(3 * 3_600), '3 h ago'],
    [-(86_400 + 60), '1 day ago'],
    [-(29 * 86_400), '29 days ago'],
  ])('%s seconds back reads "%s"', (seconds, text) => {
    expect(formatFromNow(at(seconds), now)).toBe(text)
  })

  it('shows the full date beyond 30 days', () => {
    const old = at(-31 * 86_400)
    expect(formatFromNow(old, now)).toBe(formatDateTime(old))
  })
})
