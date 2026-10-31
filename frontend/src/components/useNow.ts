import { useEffect, useState } from 'react'
import { serverNow } from '../lib/serverClock'

/** The server-clock time, refreshed every `intervalMs`, so "5 min ago" keeps moving on its own. */
export function useNow(intervalMs = 30_000): number {
  const [now, setNow] = useState(() => serverNow())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(serverNow()), intervalMs)
    return () => window.clearInterval(timer)
  }, [intervalMs])
  return now
}
