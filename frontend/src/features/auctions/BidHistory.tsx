import { useQuery } from '@tanstack/react-query'
import { formatRs } from '../../lib/money'
import { Card, PageLoader } from '../../components/ui'
import { auctionKeys, fetchBidHistory } from './api'

export function BidHistory({ auctionId }: { auctionId: number }) {
  const history = useQuery({ queryKey: auctionKeys.bids(auctionId), queryFn: () => fetchBidHistory(auctionId) })

  return (
    <Card>
      <h2 className="mb-3 font-semibold">Bid history</h2>
      {history.isPending && <PageLoader />}
      {history.isError && <p className="text-sm text-red-600">Could not load the bid history.</p>}
      {history.data && history.data.data.length === 0 && <p className="text-sm text-slate-500">No bids yet. Be the first!</p>}
      {history.data && history.data.data.length > 0 && (
        <ol className="divide-y divide-slate-100" aria-label="Bid history">
          {history.data.data.map((bid, index) => (
            <li key={bid.id} className={`flex items-center justify-between py-2 text-sm ${index === 0 ? 'font-semibold' : ''}`}>
              <span className={bid.is_you ? 'text-indigo-700' : 'text-slate-700'}>
                {bid.bidder}
                {bid.type === 'AUTO' && <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-500">auto</span>}
              </span>
              <span className="flex items-center gap-3">
                <span className="tabular-nums">{formatRs(bid.amount)}</span>
                <time className="w-20 text-right text-xs text-slate-400" dateTime={bid.created_at}>
                  {new Date(bid.created_at).toLocaleTimeString()}
                </time>
              </span>
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}
