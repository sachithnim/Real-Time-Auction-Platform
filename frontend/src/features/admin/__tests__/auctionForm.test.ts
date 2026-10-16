import { describe, expect, it } from 'vitest'
import { auctionFormSchema, isoToLocalInput, localInputToIso } from '../auctionForm'

const valid = {
  title: 'Tea chest', description: 'Teak', starting_price: 20_000, reserve_price: 50_000, min_increment: 500,
  start_at: '2030-05-01T10:00', end_at: '2030-05-01T12:00', anti_snipe_seconds: 120, extension_seconds: 120, max_extensions: 3,
}

const firstError = (values: object) => auctionFormSchema.safeParse(values).error?.issues[0]

describe('auction form schema', () => {
  it('accepts a valid auction', () => {
    expect(auctionFormSchema.safeParse(valid).success).toBe(true)
  })

  it.each([
    [{ end_at: '2030-05-01T09:00' }, 'end_at'],
    [{ end_at: '2030-05-01T10:00' }, 'end_at'],
    [{ reserve_price: 19_999 }, 'reserve_price'],
    [{ min_increment: 0 }, 'min_increment'],
    [{ starting_price: 100.5 }, 'starting_price'],
    [{ extension_seconds: 0 }, 'extension_seconds'],
    [{ max_extensions: 0 }, 'max_extensions'],
    [{ start_at: '2030-05-01T10:00', end_at: '2030-05-01T10:01', anti_snipe_seconds: 120 }, 'anti_snipe_seconds'],
  ])('rejects %o on %s', (override, field) => {
    expect(firstError({ ...valid, ...override })?.path).toEqual([field])
  })

  it('allows no reserve and disabled anti-sniping', () => {
    expect(auctionFormSchema.safeParse({ ...valid, reserve_price: null, anti_snipe_seconds: 0, extension_seconds: 0, max_extensions: 0 }).success).toBe(true)
  })
})

describe('time conversion', () => {
  it('round-trips between the local input and UTC ISO', () => {
    const iso = localInputToIso('2030-05-01T15:30')

    expect(iso).toMatch(/Z$/)
    expect(isoToLocalInput(iso)).toBe('2030-05-01T15:30')
  })
})
