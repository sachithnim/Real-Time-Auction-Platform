import { useState } from 'react'

/**
 * Counts how many times `value` has changed since the first render. Use it as a
 * `key` to replay a CSS animation on every change (e.g. a price flash), but not
 * on the first render.
 */
export function useChangeCount(value: unknown): number {
  const [previous, setPrevious] = useState(value)
  const [count, setCount] = useState(0)

  // Adjusting state while rendering, as React recommends for "react to a prop change".
  if (!Object.is(previous, value)) {
    setPrevious(value)
    setCount((c) => c + 1)
  }

  return count
}
