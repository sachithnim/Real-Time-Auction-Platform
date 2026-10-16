import { useEffect, useState } from 'react'
import { formatRemaining, inAntiSnipeWindow, remaining } from '../lib/countdown'
import { serverNow } from '../lib/serverClock'

interface Props {
  /** ISO time to count down to (e.g. end_at, which can move when the auction is extended). */
  target: string
  label?: string
  /** Highlight the final window in which a bid extends the auction. */
  antiSnipeSeconds?: number
  endedText?: string
}

/** Counts down on the server's clock, so a wrong local clock does not matter. */
export function Countdown({ target, label, antiSnipeSeconds = 0, endedText = 'Ended' }: Props) {
  const [now, setNow] = useState(() => serverNow())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(serverNow()), 250)
    return () => window.clearInterval(timer)
  }, [])

  const left = remaining(target, now)
  const urgent = inAntiSnipeWindow(left, antiSnipeSeconds)

  return (
    <div role="timer" aria-live="off">
      {label && <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>}
      <div className={`font-mono text-2xl font-semibold tabular-nums ${urgent ? 'text-red-600' : 'text-slate-900'}`}>
        {left.ended ? endedText : formatRemaining(left)}
      </div>
      {urgent && <div className="text-xs text-red-600">Bids now extend the auction</div>}
    </div>
  )
}
