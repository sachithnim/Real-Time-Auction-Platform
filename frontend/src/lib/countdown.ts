export interface Remaining {
  totalMs: number
  days: number
  hours: number
  minutes: number
  seconds: number
  ended: boolean
}

/** Time left until `endAtIso`, measured at `nowMs` (pass serverNow(), never Date.now()). */
export function remaining(endAtIso: string, nowMs: number): Remaining {
  const totalMs = Math.max(0, Date.parse(endAtIso) - nowMs)
  // Round up so the display only reaches 00:00 when the end has really arrived.
  const totalSeconds = Math.ceil(totalMs / 1000)

  return {
    totalMs,
    days: Math.floor(totalSeconds / 86_400),
    hours: Math.floor((totalSeconds % 86_400) / 3_600),
    minutes: Math.floor((totalSeconds % 3_600) / 60),
    seconds: totalSeconds % 60,
    ended: totalMs === 0,
  }
}

const pad = (value: number) => String(value).padStart(2, '0')

/** "2d 03:04:05", "03:04:05", or "04:05" under an hour. */
export function formatRemaining(r: Remaining): string {
  const clock = r.hours > 0 || r.days > 0
    ? `${pad(r.hours)}:${pad(r.minutes)}:${pad(r.seconds)}`
    : `${pad(r.minutes)}:${pad(r.seconds)}`

  return r.days > 0 ? `${r.days}d ${clock}` : clock
}

/** True inside the anti-sniping window, where a bid would extend the auction. */
export function inAntiSnipeWindow(r: Remaining, antiSnipeSeconds: number): boolean {
  return !r.ended && antiSnipeSeconds > 0 && r.totalMs <= antiSnipeSeconds * 1000
}
