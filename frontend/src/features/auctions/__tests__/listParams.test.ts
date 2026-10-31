import { describe, expect, it } from 'vitest'
import { readListParams, SEARCH_MAX, writeListParams, type ListParams } from '../listParams'

const defaults: ListParams = { status: 'ALL', category: null, search: '', sort: 'default', page: 1 }

describe('list params', () => {
  it('defaults to all auctions, every category, no search, recommended order, page 1', () => {
    expect(readListParams(new URLSearchParams())).toEqual(defaults)
  })

  it('reads the filters from the query string', () => {
    expect(readListParams(new URLSearchParams('status=live&category=art&q=brass&sort=price_high&page=3'))).toEqual({
      status: 'LIVE', category: 'art', search: 'brass', sort: 'price_high', page: 3,
    })
  })

  it('ignores values it does not understand', () => {
    expect(readListParams(new URLSearchParams('status=everything&sort=random&page=-2'))).toEqual(defaults)
    expect(readListParams(new URLSearchParams('page=1.5')).page).toBe(1)
  })

  it('cuts a search to the length the server accepts', () => {
    expect(readListParams(new URLSearchParams(`q=${'a'.repeat(150)}`)).search).toHaveLength(SEARCH_MAX)
  })

  it('writes only what differs from the defaults, and reads it back', () => {
    expect(writeListParams({ ...defaults, status: 'ALL' }).toString()).toBe('')
    expect(writeListParams({ ...defaults, status: 'ALL', search: '   ' }).toString()).toBe('')

    const params = { status: 'SCHEDULED', category: 'jewellery', search: 'gold ring', sort: 'newest', page: 2 } as const
    const search = writeListParams(params)
    expect(search.toString()).toBe('status=scheduled&category=jewellery&q=gold+ring&sort=newest&page=2')
    expect(readListParams(search)).toEqual(params)
  })
})
