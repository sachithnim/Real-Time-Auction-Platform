import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { formatRs } from '../../lib/money'
import { serverNow } from '../../lib/serverClock'
import { formatRelative } from '../../lib/time'
import { RowsSkeleton } from '../../components/Skeleton'
import { Card } from '../../components/ui'
import { auctionKeys, fetchBidHistory } from './api'

export function BidHistory({ auctionId }: { auctionId: number }) {
  const history = useQuery({ queryKey: auctionKeys.bids(auctionId), queryFn: () => fetchBidHistory(auctionId) })

  // "2 min ago" has to keep moving even when no new bid arrives.
  const [now, setNow] = useState(() => serverNow())
  useEffect(() => {
    const timer = window.setInterval(() => setNow(serverNow()), 30_000)
    return () => window.clearInterval(timer)
  }, [])

  // Bids already there when the page opened are not "new": only later ones fade in.
  const [firstIds, setFirstIds] = useState<Set<number> | null>(null)
  if (firstIds === null && history.data) {
    setFirstIds(new Set(history.data.data.map((bid) => bid.id)))
  }

  return (
    <Card>
      <h2 className="mb-3 font-semibold">Bid history</h2>
      {history.isPending && <RowsSkeleton rows={4} label="Loading bid history" />}
      {history.isError && <p className="text-sm text-red-600">Could not load the bid history.</p>}
      {history.data && history.data.data.length === 0 && <p className="text-sm text-slate-500">No bids yet. Be the first!</p>}
      {history.data && history.data.data.length > 0 && (
        <ol className="divide-y divide-slate-100" aria-label="Bid history">
          {history.data.data.map((bid, index) => (
            <li
              key={bid.id}
              data-new={firstIds !== null && !firstIds.has(bid.id) ? true : undefined}
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
    </Card>
  )
}
