import { describe, expect, it } from 'vitest'
import { bidCount } from '../labels'

describe('bidCount', () => {
  it.each([
    [undefined, '0 bids'],
    [0, '0 bids'],
    [1, '1 bid'],
    [8, '8 bids'],
  ])('%s reads "%s"', (count, text) => {
    expect(bidCount(count)).toBe(text)
  })
})
