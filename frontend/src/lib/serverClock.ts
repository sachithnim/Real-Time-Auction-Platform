/**
 * The server clock is authoritative. Every API response carries
 * meta.server_time; from it and the request's round trip we estimate how far
 * the browser clock is off, and countdowns use serverNow() instead of Date.now().
 */

/**
 * Offset = server time − the local moment the server most likely answered
 * (the midpoint of the round trip).
 */
export function computeOffset(serverTimeIso: string, sentAt: number, receivedAt: number): number {
  return Date.parse(serverTimeIso) - (sentAt + receivedAt) / 2
}

let offsetMs = 0
let bestRoundTripMs = Number.POSITIVE_INFINITY
let samples = 0

/**
 * Records one measurement. Short round trips give the most accurate estimate,
 * so a slower sample replaces the current one only when the estimate is stale.
 */
export function recordServerTime(serverTimeIso: string, sentAt: number, receivedAt: number): void {
  const roundTrip = receivedAt - sentAt
  if (!Number.isFinite(Date.parse(serverTimeIso)) || roundTrip < 0) {
    return
  }

  samples++
  if (roundTrip <= bestRoundTripMs * 1.5 || samples % 10 === 0) {
    offsetMs = computeOffset(serverTimeIso, sentAt, receivedAt)
    bestRoundTripMs = Math.min(bestRoundTripMs, roundTrip)
  }
}

/** Current time on the server's clock, in epoch milliseconds. */
export function serverNow(): number {
  return Date.now() + offsetMs
}

export function clockOffset(): number {
  return offsetMs
}

/** Test helper. */
export function resetServerClock(): void {
  offsetMs = 0
  bestRoundTripMs = Number.POSITIVE_INFINITY
  samples = 0
}
