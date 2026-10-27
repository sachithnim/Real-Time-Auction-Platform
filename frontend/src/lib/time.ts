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
