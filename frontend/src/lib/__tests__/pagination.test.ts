import { describe, expect, it } from 'vitest'
import { pageItems, pageRange } from '../pagination'

describe('pageItems', () => {
  it('lists every page when there are only a few', () => {
    expect(pageItems(1, 1)).toEqual([1])
    expect(pageItems(2, 5)).toEqual([1, 2, 3, 4, 5])
  })

  it('skips the middle with gaps around the current page', () => {
    expect(pageItems(6, 12)).toEqual([1, 'gap', 5, 6, 7, 'gap', 12])
  })

  it('keeps the ends close to the first and last page', () => {
    expect(pageItems(1, 12)).toEqual([1, 2, 'gap', 12])
    expect(pageItems(12, 12)).toEqual([1, 'gap', 11, 12])
  })

  it('shows a single skipped page instead of a gap', () => {
    expect(pageItems(4, 12)).toEqual([1, 2, 3, 4, 5, 'gap', 12])
  })
})

describe('pageRange', () => {
  it('gives the first and last item shown on a page', () => {
    expect(pageRange(1, 12, 40)).toEqual({ from: 1, to: 12 })
    expect(pageRange(2, 12, 40)).toEqual({ from: 13, to: 24 })
    expect(pageRange(4, 12, 40)).toEqual({ from: 37, to: 40 })
  })

  it('is empty when there is nothing to show', () => {
    expect(pageRange(1, 12, 0)).toEqual({ from: 0, to: 0 })
  })
})
