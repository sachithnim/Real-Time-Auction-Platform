import { describe, expect, it } from 'vitest'
import { priceLabel } from '../priceLabel'

describe('priceLabel', () => {
  it.each([
    ['a live auction with bids', { status: 'LIVE', result: null, current_price: 25_000 }, 'Current price'],
    ['a live auction without bids', { status: 'LIVE', result: null, current_price: null }, 'Starting price'],
    ['an upcoming auction', { status: 'SCHEDULED', result: null, current_price: null }, 'Starting price'],
    ['a sold auction', { status: 'COMPLETED', result: 'SOLD', current_price: 12_500 }, 'Sold for'],
    ['a reserve-not-met auction', { status: 'COMPLETED', result: 'RESERVE_NOT_MET', current_price: 47_000 }, 'Highest bid'],
    ['an auction that ended without bids', { status: 'COMPLETED', result: 'NO_BIDS', current_price: null }, 'Starting price'],
    ['a cancelled auction with bids', { status: 'CANCELLED', result: null, current_price: 9_000 }, 'Last bid'],
  ] as const)('labels %s', (_, auction, label) => {
    expect(priceLabel(auction)).toBe(label)
  })
})
