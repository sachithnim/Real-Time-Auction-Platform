import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Link, useLocation, useParams } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { NotFoundPage } from '../../components/NotFoundPage'
import { AuctionDetailSkeleton } from '../../components/Skeleton'
import { Alert, Card, StatusBadge } from '../../components/ui'
import { useChangeCount } from '../../components/useChangeCount'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useNow } from '../../components/useNow'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { serverNow } from '../../lib/serverClock'
import { formatDateTime, formatDuration, formatFromNow } from '../../lib/time'
import type { Auction } from '../../types/api'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useAuctionChannel } from '../realtime/useAuctionChannel'
import { auctionKeys, fetchAuction } from './api'
import { AuctionResultPanel } from './AuctionResultPanel'
import { BiddingPanel } from './BiddingPanel'
import { BidHistory } from './BidHistory'
import { CheckoutPanel } from './CheckoutPanel'
import { leaderBadge } from './leaderBadge'
import { priceLabel } from './priceLabel'
import { reserveBadge } from './statusText'
import { WatchButton } from './WatchButton'

/** The live auction screen. */
export function AuctionDetailPage() {
  const auctionId = Number(useParams().id)
  const auction = useQuery({ queryKey: auctionKeys.detail(auctionId), queryFn: () => fetchAuction(auctionId) })
  const realtime = useAuctionChannel(auctionId)
  useDocumentTitle(auction.data?.title)

  useRefreshAtBoundaries(auction.data)

  if (auction.isPending) {
    return <AuctionDetailSkeleton />
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
  const badge = leaderBadge(a)
  const reserve = reserveBadge(a)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <BackToList />
            <h1 className="text-2xl font-bold">{a.title}</h1>
            <StatusBadge status={a.status} />
            <WatchButton auction={a} />
          </div>
          {/* Indented past the back button (w-8 + gap-3) so it lines up with the title. */}
          <div className="flex flex-wrap items-center gap-3 pl-11">
            {a.category &&<span className="text-xs font-medium uppercase tracking-wide text-indigo-700">{a.category.name}</span>}
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
          <BidHistory auctionId={auctionId} status={a.status} />
        </div>

        {/* Price, countdown and the bid form come first on phones; beside the history on wide screens. */}
        <div className="order-first space-y-6 lg:order-none">
          <Card className="space-y-4">
            <div>
              <div className="text-xs font-medium uppercase tracking-wide text-slate-500">
                {priceLabel(a)}
              </div>
              <LivePrice amount={a.current_price ?? a.starting_price} />
              <div className="mt-1 flex flex-wrap gap-2 text-xs">
                {badge && (
                  <span className={`rounded-full px-2 py-0.5 font-medium ${badge.tone === 'good' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
                    {badge.text}
                  </span>
                )}
                {reserve && (
                  <span className={`rounded-full px-2 py-0.5 font-medium ${reserve.met ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                    {reserve.text}
                  </span>
                )}
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
              <TimeRow label={a.started_at ? 'Started' : a.status === 'CANCELLED' ? 'Was to start' : 'Starts'} iso={a.started_at ?? a.start_at} />
              {a.status === 'CANCELLED' && a.cancelled_at ? (
                <TimeRow label="Cancelled" iso={a.cancelled_at} />
              ) : (
                <TimeRow label={a.ended_at ? 'Ended' : 'Ends'} iso={a.ended_at ?? a.end_at} />
              )}
              {a.extensions_count > 0 && <TimeRow label="Originally ended" iso={a.original_end_at} />}
              {a.anti_snipe_seconds > 0 && (
                <>
                  <Row label="Anti-sniping" value={`Bids in the last ${formatDuration(a.anti_snipe_seconds)} add ${formatDuration(a.extension_seconds)}`} />
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

/** The price, briefly highlighted each time it changes (not on first load). */
function LivePrice({ amount }: { amount: number }) {
  const changes = useChangeCount(amount)

  return (
    <>
      {/* A new key restarts the highlight animation on every change. */}
      <div
        key={changes}
        className={`-mx-1 rounded px-1 text-3xl font-bold tabular-nums ${changes > 0 ? 'motion-safe:animate-price-flash' : ''}`}
        data-testid="current-price"
      >
        {formatRs(amount)}
      </div>
      {/* Screen readers hear live price changes (not the first load), politely, without moving focus. */}
      <p className="sr-only" aria-live="polite">
        {changes > 0 ? `Price is now ${formatRs(amount)}` : ''}
      </p>
    </>
  )
}

/** Back to the auction list, with the filters it had (they live in the URL, passed along by the card link). */
function BackToList() {
  const from = (useLocation().state as { listSearch?: string } | null)?.listSearch ?? ''

  return (
    <Link
      to={`/${from}`}
      aria-label="Back to all auctions"
      title="Back to all auctions"
      className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-500 ring-1 ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900"
    >
      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M15 18l-6-6 6-6" />
      </svg>
    </Link>
  )
}

/** "in 2 h 14 min" at a glance, with the exact date and time underneath. */
function TimeRow({ label, iso }: { label: string; iso: string }) {
  const now = useNow()

  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right">
        <time dateTime={iso} className="block font-medium">
          {formatFromNow(iso, now)}
        </time>
        <span className="block text-xs text-slate-500">{formatDateTime(iso)}</span>
      </dd>
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
