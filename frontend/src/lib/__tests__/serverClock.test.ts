import { afterEach, describe, expect, it, vi } from 'vitest'
import { clockOffset, computeOffset, recordServerTime, resetServerClock, serverNow } from '../serverClock'

afterEach(() => {
  resetServerClock()
  vi.useRealTimers()
})

describe('server clock', () => {
  it('uses the midpoint of the round trip', () => {
    // Sent at 1,000, received at 1,200 → the server answered around 1,100.
    expect(computeOffset(new Date(6_100).toISOString(), 1_000, 1_200)).toBe(5_000)
  })

  it('makes serverNow follow the server even when the local clock is wrong', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-01-01T10:00:00.000Z')) // local clock 2 minutes slow

    recordServerTime('2030-01-01T10:02:00.000Z', Date.now(), Date.now())

    expect(new Date(serverNow()).toISOString()).toBe('2030-01-01T10:02:00.000Z')
  })

  it('prefers measurements with a short round trip', () => {
    recordServerTime(new Date(1_100 + 5_000).toISOString(), 1_000, 1_200) // 200 ms round trip: offset 5,000
    recordServerTime(new Date(10_000 + 9_000).toISOString(), 5_000, 15_000) // 10 s round trip: ignored

    expect(clockOffset()).toBe(5_000)
  })

  it('ignores invalid samples', () => {
    recordServerTime('not a date', 1_000, 1_100)
    recordServerTime(new Date().toISOString(), 2_000, 1_000)

    expect(clockOffset()).toBe(0)
  })
})
