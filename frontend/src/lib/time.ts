/**
 * "just now", "5 min ago", "2 h ago" for recent times; the date after a day.
 * `now` is passed in (the server clock) so it is testable and not skewed by the device clock.
 */
export function formatRelative(iso: string, now: number): string {
  const seconds = Math.max(0, Math.floor((now - Date.parse(iso)) / 1000))

  if (seconds < 45) {
    return 'just now'
  }
  if (seconds < 3_600) {
    return `${Math.max(1, Math.round(seconds / 60))} min ago`
  }
  if (seconds < 86_400) {
    return `${Math.floor(seconds / 3_600)} h ago`
  }
  return new Date(iso).toLocaleDateString()
}

/** A short duration from whole seconds: "90 s" reads worse than "1 min 30 s"; "120 s" becomes "2 min". */
export function formatDuration(totalSeconds: number): string {
  if (totalSeconds < 60) {
    return `${totalSeconds} s`
  }
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return seconds === 0 ? `${minutes} min` : `${minutes} min ${seconds} s`
}

/**
 * How far a time is from now, in either direction: "in 2 h 14 min", "3 days ago".
 * Past 30 days it is just the date. `now` is the server clock, as for formatRelative.
 */
export function formatFromNow(iso: string, now: number): string {
  const diff = Date.parse(iso) - now
  const future = diff > 0
  const minutes = Math.floor(Math.abs(diff) / 60_000)

  let span: string
  if (minutes < 1) {
    return future ? 'in under a minute' : 'just now'
  } else if (minutes < 60) {
    span = `${minutes} min`
  } else if (minutes < 24 * 60) {
    const hours = Math.floor(minutes / 60)
    const rest = minutes % 60
    span = rest === 0 ? `${hours} h` : `${hours} h ${rest} min`
  } else if (minutes < 30 * 24 * 60) {
    const days = Math.floor(minutes / (24 * 60))
    span = days === 1 ? '1 day' : `${days} days`
  } else {
    return formatDateTime(iso)
  }
  return future ? `in ${span}` : `${span} ago`
}

/** A full date and time that reads naturally: "19 Oct 2026, 4:02 pm" (in the viewer's locale). */
export function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
