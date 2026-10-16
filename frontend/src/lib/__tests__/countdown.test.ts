import { describe, expect, it } from 'vitest'
import { formatRemaining, inAntiSnipeWindow, remaining } from '../countdown'

const END = '2030-03-01T20:00:00.000Z'
const at = (iso: string) => Date.parse(iso)

describe('remaining', () => {
  it('splits the time left into days, hours, minutes and seconds', () => {
    const r = remaining(END, at('2030-02-27T16:56:55.000Z'))

    expect([r.days, r.hours, r.minutes, r.seconds]).toEqual([2, 3, 3, 5])
    expect(r.ended).toBe(false)
  })

  it('rounds up partial seconds so it never shows 00:00 early', () => {
    expect(formatRemaining(remaining(END, at('2030-03-01T19:59:59.200Z')))).toBe('00:01')
  })

  it('is ended exactly at the end time and after it', () => {
    expect(remaining(END, at(END)).ended).toBe(true)
    expect(remaining(END, at('2030-03-01T20:00:05.000Z'))).toMatchObject({ totalMs: 0, ended: true })
  })

  it('follows a moved end time (anti-sniping extension)', () => {
    const now = at('2030-03-01T19:59:20.000Z')

    expect(formatRemaining(remaining(END, now))).toBe('00:40')
    expect(formatRemaining(remaining('2030-03-01T20:02:00.000Z', now))).toBe('02:40')
  })
})

describe('formatRemaining', () => {
  it.each([
    ['2030-03-01T19:55:30.000Z', '04:30'],
    ['2030-03-01T17:00:00.000Z', '03:00:00'],
    ['2030-02-28T19:00:00.000Z', '1d 01:00:00'],
  ])('at %s shows %s', (now, expected) => {
    expect(formatRemaining(remaining(END, at(now)))).toBe(expected)
  })
})

describe('inAntiSnipeWindow', () => {
  it('is true only inside the final window and before the end', () => {
    expect(inAntiSnipeWindow(remaining(END, at('2030-03-01T19:57:59.000Z')), 120)).toBe(false)
    expect(inAntiSnipeWindow(remaining(END, at('2030-03-01T19:58:00.000Z')), 120)).toBe(true)
    expect(inAntiSnipeWindow(remaining(END, at(END)), 120)).toBe(false)
    expect(inAntiSnipeWindow(remaining(END, at('2030-03-01T19:59:00.000Z')), 0)).toBe(false)
  })
})
