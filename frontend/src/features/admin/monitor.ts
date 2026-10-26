import type { MonitorAuction, MonitorAuctionGone, MonitorRejection, MonitorSnapshot } from '../../types/api'

/** How many rejected bids the monitor keeps on screen (matches the API). */
export const RECENT_REJECTIONS = 10

const byEndTime = (a: MonitorAuction, b: MonitorAuction) => Date.parse(a.end_at) - Date.parse(b.end_at) || a.id - b.id

/**
 * Applies one live update to the monitor. A row that is no longer LIVE (ended,
 * cancelled or gone) leaves the list; a new LIVE row joins it; an older copy of
 * a row we already have (lower sequence) is ignored.
 */
export function applyMonitorAuction(snapshot: MonitorSnapshot, row: MonitorAuction | MonitorAuctionGone): MonitorSnapshot {
  const others = snapshot.auctions.filter((auction) => auction.id !== row.id)

  if (row.status !== 'LIVE') {
    return others.length === snapshot.auctions.length ? snapshot : { ...snapshot, auctions: others }
  }

  const current = snapshot.auctions.find((auction) => auction.id === row.id)
  if (current && row.sequence < current.sequence) {
    return snapshot
  }

  return { ...snapshot, auctions: [...others, row].sort(byEndTime) }
}

/** Adds a rejected bid to the top of the list, once, keeping the newest few. */
export function addMonitorRejection(snapshot: MonitorSnapshot, rejection: MonitorRejection): MonitorSnapshot {
  if (snapshot.recent_rejections.some((existing) => existing.id === rejection.id)) {
    return snapshot
  }

  return { ...snapshot, recent_rejections: [rejection, ...snapshot.recent_rejections].slice(0, RECENT_REJECTIONS) }
}

const reasonLabels: Record<string, string> = {
  BID_TOO_LOW: 'Below the minimum',
  INVALID_BID: 'Invalid or already leading',
  MAX_AUTO_BID_REACHED: 'Maximum too low',
  AUCTION_ENDED: 'After the end',
  AUCTION_NOT_STARTED: 'Before the start',
  AUCTION_NOT_LIVE: 'Auction not open',
}

/** A short, readable reason for a rejected bid. */
export function rejectionReason(code: string | null): string {
  return code === null ? 'Unknown' : (reasonLabels[code] ?? code)
}
