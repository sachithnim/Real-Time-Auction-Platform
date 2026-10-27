import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { useChangeCount } from '../useChangeCount'

describe('useChangeCount', () => {
  it('starts at zero, so nothing animates on first render', () => {
    const { result } = renderHook(() => useChangeCount(25_000))

    expect(result.current).toBe(0)
  })

  it('counts each change and ignores re-renders with the same value', () => {
    const { result, rerender } = renderHook(({ price }) => useChangeCount(price), { initialProps: { price: 25_000 } })

    rerender({ price: 25_000 })
    expect(result.current).toBe(0)

    rerender({ price: 25_500 })
    expect(result.current).toBe(1)

    rerender({ price: 25_500 })
    expect(result.current).toBe(1)

    rerender({ price: 26_000 })
    expect(result.current).toBe(2)
  })
})
