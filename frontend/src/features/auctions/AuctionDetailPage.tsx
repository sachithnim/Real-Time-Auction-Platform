import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useParams } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { NotFoundPage } from '../../components/NotFoundPage'
import { Alert, Card, PageLoader, StatusBadge } from '../../components/ui'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { serverNow } from '../../lib/serverClock'
import type { Auction } from '../../types/api'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useAuctionChannel } from '../realtime/useAuctionChannel'
import { auctionKeys, fetchAuction } from './api'
import { AuctionResultPanel } from './AuctionResultPanel'
import { BiddingPanel } from './BiddingPanel'
import { BidHistory } from './BidHistory'
import { CheckoutPanel } from './CheckoutPanel'
import { WatchButton } from './WatchButton'

/** The live auction screen. */
export function AuctionDetailPage() {
  const auctionId = Number(useParams().id)
  const auction = useQuery({ queryKey: auctionKeys.detail(auctionId), queryFn: () => fetchAuction(auctionId) })
  const realtime = useAuctionChannel(auctionId)

  useRefreshAtBoundaries(auction.data)

  if (auction.isPending) {
    return <PageLoader />
  }

  if (auction.isError) {
    const info = parseApiError(auction.error)
    return info.code === 'AUCTION_NOT_FOUND' ? (
      <NotFoundPage message="This auction does not exist." />
    ) : (
      <Alert>
        {friendlyMessage(info)}{' '}
        <button type="button" className="font-semibold underline" onClick={() => auction.refetch()}>
          Try again
        </button>
      </Alert>
    )
  }

  const a = auction.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold">{a.title}</h1>
            <StatusBadge status={a.status} />
            <WatchButton auction={a} />
          </div>
          <div className="flex flex-wrap items-center gap-3">
            {a.category && <span className="text-xs font-medium uppercase tracking-wide text-indigo-700">{a.category.name}</span>}
            <RealtimeBadge status={realtime} />
          </div>
        </div>
      </div>

      <AuctionResultPanel auction={a} />
      {a.is_winner && a.status === 'COMPLETED' && a.result === 'SOLD' && <CheckoutPanel auction={a} />}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <p className="whitespace-pre-line text-slate-700">{a.description}</p>
          </Card>
          <BidHistory auctionId={auctionId} />
        </div>

        <div className="space-y-6">
          <Card className="space-y-4">
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {a.current_price === null ? 'Starting price' : a.status === 'COMPLETED' ? 'Final bid' : 'Current price'}
              </div>
              <div className="text-3xl font-bold tabular-nums" data-testid="current-price">
                {formatRs(a.current_price ?? a.starting_price)}
              </div>
              <div className="mt-1 flex flex-wrap gap-2 text-xs">
                {a.leader && (
                  <span className={`rounded-full px-2 py-0.5 font-medium ${a.is_leading ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
                    {a.is_leading ? 'You are leading' : `Leader: ${a.leader}`}
                  </span>
                )}
                <span className={`rounded-full px-2 py-0.5 font-medium ${a.reserve_met ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                  {a.reserve_met ? 'Reserve met' : 'Reserve not met'}
                </span>
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{a.bids_count ?? 0} bids</span>
              </div>
            </div>

            {a.status === 'LIVE' && <Countdown target={a.end_at} label="Ends in" antiSnipeSeconds={a.anti_snipe_seconds} endedText="Closing…" />}
            {a.status === 'SCHEDULED' && <Countdown target={a.start_at} label="Starts in" endedText="Starting…" />}

            <BiddingPanel auction={a} />
          </Card>

          <Card>
            <h2 className="mb-2 font-semibold">Details</h2>
            <dl className="space-y-1 text-sm">
              <Row label="Starting price" value={formatRs(a.starting_price)} />
              <Row label="Minimum increment" value={formatRs(a.min_increment)} />
              <Row label="Starts" value={new Date(a.start_at).toLocaleString()} />
              <Row label="Ends" value={new Date(a.end_at).toLocaleString()} />
              {a.extensions_count > 0 && <Row label="Originally ended" value={new Date(a.original_end_at).toLocaleString()} />}
              {a.anti_snipe_seconds > 0 && (
                <>
                  <Row label="Anti-sniping" value={`Bids in the last ${Math.round(a.anti_snipe_seconds / 60 * 10) / 10} min add ${Math.round(a.extension_seconds / 60 * 10) / 10} min`} />
                  <Row label="Extensions used" value={`${a.extensions_count} of ${a.max_extensions}`} />
                </>
              )}
            </dl>
          </Card>
        </div>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium">{value}</dd>
    </div>
  )
}

/**
 * Safety net if a start/end event is missed: shortly after the scheduled start
 * or end, re-fetch so the screen never stays stuck on an old status.
 */
function useRefreshAtBoundaries(auction: Auction | undefined) {
  const queryClient = useQueryClient()

  useEffect(() => {
    if (!auction || (auction.status !== 'LIVE' && auction.status !== 'SCHEDULED')) {
      return
    }

    const boundary = Date.parse(auction.status === 'LIVE' ? auction.end_at : auction.start_at)
    const delay = Math.max(0, boundary - serverNow()) + 3_000
    const timer = window.setTimeout(() => queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auction.id) }), delay)

    return () => window.clearTimeout(timer)
  }, [auction, queryClient])
}
