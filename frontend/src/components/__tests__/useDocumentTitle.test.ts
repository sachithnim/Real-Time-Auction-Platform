import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useDocumentTitle } from '../useDocumentTitle'

describe('useDocumentTitle', () => {
  it('names the tab after the page', () => {
    renderHook(() => useDocumentTitle('Hand-Carved Kandyan Mask'))

    expect(document.title).toBe('Hand-Carved Kandyan Mask · LiveBid')
  })

  it('follows a title that arrives later, e.g. once the auction has loaded', () => {
    const { rerender } = renderHook(({ title }) => useDocumentTitle(title), { initialProps: { title: null as string | null } })
    expect(document.title).toBe('LiveBid · Real-Time Auctions')

    rerender({ title: 'Vintage Ceylon Tea Chest' })
    expect(document.title).toBe('Vintage Ceylon Tea Chest · LiveBid')
  })
})
