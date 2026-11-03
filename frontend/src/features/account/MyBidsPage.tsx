import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { CoverPhoto } from '../../components/CoverPhoto'
import { EmptyState } from '../../components/EmptyState'
import { ErrorState } from '../../components/ErrorState'
import { Pagination } from '../../components/Pagination'
import { RowsSkeleton } from '../../components/Skeleton'
import { onTabListKeyDown } from '../../components/tabs'
import { Card } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useNow } from '../../components/useNow'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { getEcho } from '../../lib/echo'
import { formatRs } from '../../lib/money'
import { formatDateTime, formatFromNow } from '../../lib/time'
import type { MyBidAuction, MyBidCounts } from '../../types/api'
import { priceLabel } from '../auctions/priceLabel'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useRealtimeStatus } from '../realtime/useRealtimeStatus'
import { fetchMyBids, myBidsFilters, myBidsKeys, myBidSummary, readFilter, type MyBidsFilter, type MyBidSummary } from './myBids'

const badgeTones: Record<MyBidSummary['badge']['tone'], string> = {
  good: 'bg-emerald-100 text-emerald-800',
  warning: 'bg-amber-100 text-amber-900',
  neutral: 'bg-slate-100 text-slate-700',
}

const countFor: Record<MyBidsFilter, keyof MyBidCounts> = { all: 'all', active: 'active', won: 'won', lost: 'lost' }

/** Every auction the bidder has bid on, how they stand in each, and what to do next. */
export function MyBidsPage() {
  useDocumentTitle('My bids')
  // The tab and page live in the URL, so "back" from an auction returns to the same view.
  const [searchParams, setSearchParams] = useSearchParams()
  const filter = readFilter(searchParams.get('filter'))
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const show = (next: MyBidsFilter, nextPage = 1) => {
    const params = new URLSearchParams()
    if (next !== 'all') params.set('filter', next)
    if (nextPage > 1) params.set('page', String(nextPage))
    setSearchParams(params, { replace: true })
  }

  const queryClient = useQueryClient()
  const realtime = useRealtimeStatus(() => queryClient.invalidateQueries({ queryKey: myBidsKeys.all }))
  const bids = useQuery({
    queryKey: myBidsKeys.list(filter, page),
    queryFn: () => fetchMyBids(filter, page),
    placeholderData: keepPreviousData,
  })

  // A bid, extension or close on one of these auctions: ask the server again, which decides
  // the new state (leading, outbid, won) and which tab the auction now belongs in.
  const shownIds = useRef<Set<number>>(new Set())
  useEffect(() => {
    shownIds.current = new Set(bids.data?.data.map((a) => a.id))
  }, [bids.data])
  useEffect(() => {
    const channel = getEcho()?.channel('auctions')
    channel?.listen('.AuctionUpdated', (update: { id: number }) => {
      if (shownIds.current.has(update.id)) {
        queryClient.invalidateQueries({ queryKey: myBidsKeys.all })
      }
    })
    return () => {
      getEcho()?.leaveChannel('auctions')
    }
  }, [queryClient])

  const counts = bids.data?.counts

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">My bids</h1>
        <RealtimeBadge status={realtime} />
      </div>

      {counts && counts.to_pay > 0 && filter !== 'won' && (
        <div role="status" className="flex flex-col gap-3 rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-200 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-emerald-900">
            <span className="font-semibold">You won {counts.to_pay === 1 ? 'an auction' : `${counts.to_pay} auctions`}!</span>{' '}
            {counts.to_pay === 1 ? 'It is waiting for your payment.' : 'They are waiting for your payment.'}
          </p>
          <button type="button" onClick={() => show('won')} className="shrink-0 rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-600">
            See my wins
          </button>
        </div>
      )}

      <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div className="flex min-w-max gap-6 border-b border-slate-200" role="tablist" aria-label="Show my bids" onKeyDown={onTabListKeyDown}>
          {myBidsFilters.map((tab) => {
            const active = filter === tab.value
            const count = counts?.[countFor[tab.value]]
            return (
              <button
                key={tab.value}
                type="button"
                role="tab"
                aria-selected={active}
                tabIndex={active ? 0 : -1}
                onClick={() => show(tab.value)}
                className={`-mb-px flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-medium transition ${
                  active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
                }`}
              >
                {tab.label}
                {/* The space makes screen readers say "Won 2", not "Won2". */}
                {count !== undefined && ' '}
                {count !== undefined && (
                  <span className={`rounded-full px-1.5 py-0.5 text-xs tabular-nums ${active ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600'}`}>{count}</span>
                )}
              </button>
            )
          })}
        </div>
      </div>

      {bids.isPending && <RowsSkeleton rows={4} label="Loading your bids" />}
      {bids.isError && (
        <ErrorState title="Could not load your bids" onRetry={() => bids.refetch()}>
          {friendlyMessage(parseApiError(bids.error))}
        </ErrorState>
      )}

      {bids.data && (
        <>
          {bids.data.data.length === 0 ? (
            <MyBidsEmpty filter={filter} onShowAll={() => show('all')} />
          ) : (
            <ul
              className={`space-y-3 transition-opacity ${bids.isPlaceholderData ? 'pointer-events-none opacity-50' : ''}`}
              aria-busy={bids.isPlaceholderData}
            >
              {bids.data.data.map((auction) => (
                <li key={auction.id}>
                  <MyBidRow auction={auction} />
                </li>
              ))}
            </ul>
          )}

          {bids.data.data.length > 0 && (
            <Pagination
              page={bids.data.meta.current_page}
              lastPage={bids.data.meta.last_page}
              perPage={bids.data.meta.per_page}
              total={bids.data.meta.total}
              noun={['auction', 'auctions']}
              onChange={(next) => show(filter, next)}
            />
          )}
        </>
      )}
    </div>
  )
}

function MyBidsEmpty({ filter, onShowAll }: { filter: MyBidsFilter; onShowAll: () => void }) {
  if (filter === 'all') {
    return (
      <EmptyState
        icon="🔨"
        title="You haven’t bid on anything yet"
        action={
          <Link to="/?status=LIVE" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Browse live auctions
          </Link>
        }
      >
        Every auction you bid on shows here, with whether you’re leading, outbid or have won.
      </EmptyState>
    )
  }

  const messages: Record<Exclude<MyBidsFilter, 'all'>, { icon: string; title: string; body: string }> = {
    active: { icon: '⏱️', title: 'No live auctions with your bids', body: 'Auctions you bid on show here while they are live.' },
    won: { icon: '🏆', title: 'No wins yet', body: 'Auctions you win show here, ready to pay.' },
    lost: { icon: '🤝', title: 'Nothing here', body: 'Auctions you bid on but did not win show here.' },
  }
  const message = messages[filter]

  return (
    <EmptyState
      icon={message.icon}
      title={message.title}
      action={
        <button type="button" onClick={onShowAll} className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-300 hover:bg-slate-50">
          Show all my bids
        </button>
      }
    >
      {message.body}
    </EmptyState>
  )
}

function MyBidRow({ auction }: { auction: MyBidAuction }) {
  const summary = myBidSummary(auction)
  const mine = auction.my_bid
  // Handed to the auction page so its back button returns here, to the same tab.
  const linkState = { fromMyBids: useLocation().search }
  const price = auction.final_price ?? auction.current_price ?? auction.starting_price

  return (
    <Card className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
      <Link to={`/auctions/${auction.id}`} state={linkState} className="shrink-0" tabIndex={-1} aria-hidden>
        <CoverPhoto image={auction.images?.[0]} className="aspect-[4/3] w-full rounded-md sm:w-36" />
      </Link>

      <div className="min-w-0 flex-1 space-y-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${badgeTones[summary.badge.tone]}`}>{summary.badge.text}</span>
          {auction.category && <span className="text-xs font-medium uppercase tracking-wide text-indigo-700">{auction.category.name}</span>}
        </div>
        <h2 className="font-semibold leading-snug">
          <Link to={`/auctions/${auction.id}`} state={linkState} className="hover:text-indigo-700 hover:underline">
            {auction.title}
          </Link>
        </h2>
        {summary.note && <p className="text-sm text-slate-600">{summary.note}</p>}

        <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
          <div>
            <dt className="text-xs text-slate-500">{priceLabel(auction)}</dt>
            <dd className="font-semibold tabular-nums">{formatRs(price)}</dd>
          </div>
          <div>
            <dt className="text-xs text-slate-500">Your highest bid</dt>
            <dd className="font-semibold tabular-nums">{formatRs(mine.highest_bid)}</dd>
          </div>
          {mine.max_amount !== null && (
            <div>
              <dt className="text-xs text-slate-500">Your maximum</dt>
              <dd className="font-semibold tabular-nums">
                {formatRs(mine.max_amount)}
                {/* Private: only the bidder ever sees their own maximum. */}
                <span className="ml-1 text-xs font-normal text-slate-500">· only you see this</span>
              </dd>
            </div>
          )}
          <div>
            <dt className="text-xs text-slate-500">Your bids</dt>
            <dd className="font-semibold tabular-nums">{mine.bids_count}</dd>
          </div>
        </dl>
      </div>

      <div className="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:justify-center">
        <div className="text-left sm:text-right">
          {auction.status === 'LIVE' ? <Countdown target={auction.end_at} label="Ends in" /> : <ClosedWhen auction={auction} />}
        </div>
        <Link
          to={`/auctions/${auction.id}`}
          state={linkState}
          className={`shrink-0 rounded-md px-4 py-2 text-sm font-semibold shadow-sm ${
            summary.action.primary ? 'bg-indigo-600 text-white hover:bg-indigo-500' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'
          }`}
        >
          {summary.action.label}
          <span className="sr-only">: {auction.title}</span>
        </Link>
      </div>
    </Card>
  )
}

/** "Ended 3 days ago" for a closed auction, with the exact time on hover. */
function ClosedWhen({ auction }: { auction: MyBidAuction }) {
  const now = useNow(60_000)
  const cancelled = auction.status === 'CANCELLED'
  const iso = cancelled ? (auction.cancelled_at ?? auction.end_at) : (auction.ended_at ?? auction.end_at)

  return (
    <time dateTime={iso} title={formatDateTime(iso)} className="block text-sm text-slate-500">
      {cancelled ? 'Cancelled' : 'Ended'} {formatFromNow(iso, now)}
    </time>
  )
}
