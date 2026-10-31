import { describe, expect, it } from 'vitest'
import { readListParams, writeListParams } from '../listParams'

describe('list params', () => {
  it('defaults to all auctions, every category, page 1', () => {
    expect(readListParams(new URLSearchParams())).toEqual({ status: 'ALL', category: null, page: 1 })
  })

  it('reads the filters from the query string', () => {
    expect(readListParams(new URLSearchParams('status=live&category=art&page=3'))).toEqual({ status: 'LIVE', category: 'art', page: 3 })
  })

  it('ignores values it does not understand', () => {
    expect(readListParams(new URLSearchParams('status=everything&page=-2'))).toEqual({ status: 'ALL', category: null, page: 1 })
    expect(readListParams(new URLSearchParams('page=1.5')).page).toBe(1)
  })

  it('writes only what differs from the defaults, and reads it back', () => {
    expect(writeListParams({ status: 'ALL', category: null, page: 1 }).toString()).toBe('')

    const params = { status: 'SCHEDULED', category: 'jewellery', page: 2 } as const
    const search = writeListParams(params)
    expect(search.toString()).toBe('status=scheduled&category=jewellery&page=2')
    expect(readListParams(search)).toEqual(params)
  })
})
