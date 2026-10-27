import { describe, expect, it } from 'vitest'
import { bidStep, quickBidAmounts } from '../quickBids'

describe('quick bid amounts', () => {
  it('steps by the tier increment the server applies at the current price', () => {
    // Assignment example: price 25,000, increment 500 → minimum next bid 25,500.
    const auction = { starting_price: 20_000, current_price: 25_000, min_next_bid: 25_500, min_increment: 100 }

    expect(bidStep(auction)).toBe(500)
    expect(quickBidAmounts(auction)).toEqual([25_500, 26_000, 26_500])
  })

  it('follows a larger tier once the price crosses into it', () => {
    const auction = { starting_price: 20_000, current_price: 60_000, min_next_bid: 61_000, min_increment: 100 }

    expect(quickBidAmounts(auction)).toEqual([61_000, 62_000, 63_000])
  })

  it('starts from the starting price before the first bid', () => {
    const auction = { starting_price: 5_000, current_price: null, min_next_bid: 5_000, min_increment: 250 }

    expect(bidStep(auction)).toBe(250)
    expect(quickBidAmounts(auction)).toEqual([5_000, 5_250, 5_500])
  })

  it('falls back to the starting price when the server sent no minimum', () => {
    const auction = { starting_price: 8_000, current_price: null, min_next_bid: null, min_increment: 100 }

    expect(quickBidAmounts(auction)).toEqual([8_000, 8_100, 8_200])
  })
})
