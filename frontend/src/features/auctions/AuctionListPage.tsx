import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { Alert, Button, Card, PageLoader, StatusBadge } from '../../components/ui'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { getEcho } from '../../lib/echo'
import { resultLabels } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import type { Auction, AuctionStatus, Paginated } from '../../types/api'
import { useAuth } from '../auth/useAuth'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useRealtimeStatus } from '../realtime/useRealtimeStatus'
import { auctionKeys, fetchAuctions, type AuctionListFilter } from './api'
import { WatchButton } from './WatchButton'

const tabs: { value: AuctionListFilter; label: string }[] = [
  { value: 'ALL', label: 'All' },
  { value: 'LIVE', label: 'Live' },
  { value: 'SCHEDULED', label: 'Upcoming' },
  { value: 'COMPLETED', label: 'Finished' },
]

const watchingTab: { value: AuctionListFilter; label: string } = { value: 'WATCHING', label: 'Watching' }

interface ListingUpdate {
  id: number
  status: AuctionStatus
  result: Auction['result']
  current_price: number | null
  end_at: string
  bids_count: number
  sequence: number
}

export function AuctionListPage() {
  const { user } = useAuth()
  const isBidder = user?.role === 'BIDDER'
  const visibleTabs = isBidder ? [...tabs, watchingTab] : tabs
  const [selected, setStatus] = useState<AuctionListFilter>('ALL')
  // The watchlist belongs to a signed-in bidder; after logging out, fall back to all auctions.
  const status = selected === 'WATCHING' && !isBidder ? 'ALL' : selected
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const realtime = useRealtimeStatus(() => queryClient.invalidateQueries({ queryKey: auctionKeys.all }))

  const auctions = useQuery({
    queryKey: auctionKeys.list(status, page),
    queryFn: () => fetchAuctions(status, page),
    placeholderData: keepPreviousData,
  })

  // Live listing updates: patch the matching card when the update is newer than what we show.
  useEffect(() => {
    const channel = getEcho()?.channel('auctions')
    channel?.listen('.AuctionUpdated', (update: ListingUpdate) => {
      let known = false
      queryClient.setQueriesData<Paginated<Auction>>({ queryKey: ['auctions', 'list'] }, (current) => {
        if (!current) {
          return current
        }
        return {
          ...current,
          data: current.data.map((auction) => {
            if (auction.id !== update.id || update.sequence <= auction.sequence) {
              return auction
            }
            known = true
            return { ...auction, ...update, bids_count: update.bids_count }
          }),
        }
      })
      if (!known) {
        // A new or moved auction (e.g. just started): let the server decide where it belongs.
        queryClient.invalidateQueries({ queryKey: ['auctions', 'list'] })
      }
    })
    return () => {
      getEcho()?.leaveChannel('auctions')
    }
  }, [queryClient])

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Auctions</h1>
          <RealtimeBadge status={realtime} />
        </div>
        <div className="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200" role="tablist">
          {visibleTabs.map((tab) => (
            <button
              key={tab.value}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              onClick={() => {
                setStatus(tab.value)
                setPage(1)
              }}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === tab.value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {auctions.isPending && <PageLoader />}
      {auctions.isError && (
        <Alert>
          {friendlyMessage(parseApiError(auctions.error))}{' '}
          <button type="button" className="font-semibold underline" onClick={() => auctions.refetch()}>
            Try again
          </button>
        </Alert>
      )}

      {auctions.data && (
        <>
          {auctions.data.data.length === 0 ? (
            <p className="py-12 text-center text-slate-500">
              {status === 'WATCHING' ? 'You are not watching any auctions yet. Use ☆ Watch on an auction to follow it.' : 'No auctions here yet.'}
            </p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {auctions.data.data.map((auction) => (
                <AuctionCard key={auction.id} auction={auction} />
              ))}
            </div>
          )}

          {auctions.data.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3">
              <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span className="text-sm text-slate-500">
                Page {auctions.data.meta.current_page} of {auctions.data.meta.last_page}
              </span>
              <Button variant="secondary" disabled={page >= auctions.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

function AuctionCard({ auction }: { auction: Auction }) {
  return (
    <Link to={`/auctions/${auction.id}`} className="block transition hover:-translate-y-0.5">
      <Card className="h-full space-y-3 hover:ring-indigo-300">
        <div className="flex items-start justify-between gap-2">
          <h2 className="font-semibold leading-snug">{auction.title}</h2>
          <div className="flex shrink-0 flex-col items-end gap-1.5">
            <StatusBadge status={auction.status} />
            <WatchButton auction={auction} />
          </div>
        </div>
        <p className="line-clamp-2 text-sm text-slate-600">{auction.description}</p>
        <div className="flex items-end justify-between">
          <div>
            <div className="text-xs text-slate-500">{auction.current_price === null ? 'Starting price' : 'Current price'}</div>
            <div className="text-lg font-bold">{formatRs(auction.current_price ?? auction.starting_price)}</div>
            <div className="text-xs text-slate-500">{auction.bids_count ?? 0} bids</div>
          </div>
          <div className="text-right">
            {auction.status === 'LIVE' && <Countdown target={auction.end_at} label="Ends in" />}
            {auction.status === 'SCHEDULED' && <Countdown target={auction.start_at} label="Starts in" endedText="Starting…" />}
            {auction.status === 'COMPLETED' && auction.result && (
              <span className="text-sm font-medium text-violet-700">{resultLabels[auction.result]}</span>
            )}
            {auction.status === 'CANCELLED' && <span className="text-sm font-medium text-red-700">Cancelled</span>}
          </div>
        </div>
      </Card>
    </Link>
  )
}
