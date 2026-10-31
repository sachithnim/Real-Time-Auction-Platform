import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { formatRs } from '../../lib/money'
import { formatRelative } from '../../lib/time'
import { LoadMore } from '../../components/Pagination'
import { RowsSkeleton } from '../../components/Skeleton'
import { Card } from '../../components/ui'
import { useNow } from '../../components/useNow'
import type { AuctionStatus, BidHistoryRow } from '../../types/api'
import { auctionKeys, fetchBidHistory } from './api'
import { emptyHistoryText } from './statusText'

interface OlderBids {
  auctionId: number
  rows: BidHistoryRow[]
  /** Where the next older page starts; null once there is nothing older. */
  cursor: string | null
}

export function BidHistory({ auctionId, status }: { auctionId: number; status: AuctionStatus }) {
  // The newest page: live events add new bids to it (see useAuctionChannel).
  const history = useQuery({ queryKey: auctionKeys.bids(auctionId), queryFn: () => fetchBidHistory(auctionId) })

  // "2 min ago" has to keep moving even when no new bid arrives.
  const now = useNow()

  // Bids already there when the page opened are not "new": only later ones fade in. Ids are
  // assigned in order under the auction lock, so "new" simply means a higher id than any of those.
  const [newestOnOpen, setNewestOnOpen] = useState<number | null>(null)
  if (newestOnOpen === null && history.data) {
    setNewestOnOpen(Math.max(0, ...history.data.data.map((bid) => bid.id)))
  }

  // Older pages, loaded on request. Bids are never changed or deleted, so they can be kept as they are.
  const [stored, setOlder] = useState<OlderBids | null>(null)
  const older = stored?.auctionId === auctionId ? stored : null
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [olderError, setOlderError] = useState(false)
  const nextCursor = older ? older.cursor : (history.data?.meta?.next_cursor ?? null)

  const loadOlder = async () => {
    if (!nextCursor) {
      return
    }
    setLoadingOlder(true)
    setOlderError(false)
    try {
      const page = await fetchBidHistory(auctionId, nextCursor)
      setOlder({ auctionId, rows: [...(older?.rows ?? []), ...page.data], cursor: page.meta?.next_cursor ?? null })
    } catch {
      setOlderError(true)
    } finally {
      setLoadingOlder(false)
    }
  }

  // Newest first, each bid once: a refetched first page can overlap the older pages.
  const rows = history.data ? dedupeNewestFirst([...history.data.data, ...(older?.rows ?? [])]) : []

  return (
    <Card>
      <h2 className="mb-3 font-semibold">Bid history</h2>
      {history.isPending && <RowsSkeleton rows={4} label="Loading bid history" />}
      {history.isError && <p className="text-sm text-red-600">Could not load the bid history.</p>}
      {history.data && rows.length === 0 && <p className="text-sm text-slate-500">{emptyHistoryText(status)}</p>}
      {rows.length > 0 && (
        <ol className="divide-y divide-slate-100" aria-label="Bid history">
          {rows.map((bid, index) => (
            <li
              key={bid.id}
              data-new={newestOnOpen !== null && bid.id > newestOnOpen ? true : undefined}
              className={`flex items-center justify-between py-2 text-sm data-new:motion-safe:animate-row-in ${index === 0 ? 'font-semibold' : ''}`}
            >
              <span className={bid.is_you ? 'text-indigo-700' : 'text-slate-700'}>
                {bid.bidder}
                {bid.type === 'AUTO' && <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">auto</span>}
              </span>
              <span className="flex items-center gap-3">
                <span className="tabular-nums">{formatRs(bid.amount)}</span>
                <time className="w-24 text-right text-xs text-slate-500" dateTime={bid.created_at} title={new Date(bid.created_at).toLocaleString()}>
                  {formatRelative(bid.created_at, now)}
                </time>
              </span>
            </li>
          ))}
        </ol>
      )}
      {olderError && <p className="pt-2 text-center text-sm text-red-600">Could not load older bids. Try again.</p>}
      {nextCursor && <LoadMore onClick={loadOlder} loading={loadingOlder} label="Load older bids" />}
    </Card>
  )
}

function dedupeNewestFirst(bids: BidHistoryRow[]): BidHistoryRow[] {
  const byId = new Map(bids.map((bid) => [bid.id, bid]))
  return [...byId.values()].sort((a, b) => b.id - a.id)
}
