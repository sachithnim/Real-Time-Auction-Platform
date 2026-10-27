import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { EmptyState } from '../../components/EmptyState'
import { AuctionCardsSkeleton } from '../../components/Skeleton'
import { Alert, Button, Card, StatusBadge } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { getEcho } from '../../lib/echo'
import { resultLabels } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import type { Auction, AuctionStatus, Paginated } from '../../types/api'
import { useAuth } from '../auth/useAuth'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useRealtimeStatus } from '../realtime/useRealtimeStatus'
import { auctionKeys, fetchAuctions, fetchCategories, type AuctionListFilter } from './api'
import { emptyListMessage } from './emptyList'
import { priceLabel } from './priceLabel'
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
  useDocumentTitle('Auctions')
  const { user } = useAuth()
  const isBidder = user?.role === 'BIDDER'
  const visibleTabs = isBidder ? [...tabs, watchingTab] : tabs
  const [selected, setStatus] = useState<AuctionListFilter>('ALL')
  // The watchlist belongs to a signed-in bidder; after logging out, fall back to all auctions.
  const status = selected === 'WATCHING' && !isBidder ? 'ALL' : selected
  const [category, setCategory] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const realtime = useRealtimeStatus(() => queryClient.invalidateQueries({ queryKey: auctionKeys.all }))
  const categories = useQuery({ queryKey: auctionKeys.categories, queryFn: fetchCategories, staleTime: 5 * 60_000 })

  const auctions = useQuery({
    queryKey: auctionKeys.list(status, category, page),
    queryFn: () => fetchAuctions(status, page, category),
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
        <div className="flex flex-wrap items-center gap-3">
        <label className="text-sm text-slate-600">
          <span className="sr-only">Category</span>
          <select
            aria-label="Category"
            className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm"
            value={category ?? ''}
            onChange={(e) => {
              setCategory(e.target.value || null)
              setPage(1)
            }}
          >
            <option value="">All categories</option>
            {categories.data?.map((c) => (
              <option key={c.slug} value={c.slug}>
                {c.name} ({c.auctions_count ?? 0})
              </option>
            ))}
          </select>
        </label>
        <div className="flex max-w-full gap-1 overflow-x-auto rounded-lg bg-white p-1 ring-1 ring-slate-200" role="tablist">
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
              className={`shrink-0 rounded-md px-3 py-1.5 text-sm font-medium ${status === tab.value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {tab.label}
            </button>
          ))}
        </div>
        </div>
      </div>

      {auctions.isPending && <AuctionCardsSkeleton />}
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
            <ListEmpty
              status={status}
              category={category}
              onClear={(filter) => {
                if (filter === 'category') {
                  setCategory(null)
                } else {
                  setStatus('ALL')
                }
                setPage(1)
              }}
            />
          ) : (
            // While another tab or page loads, the previous results stay visible but faded.
            <div
              className={`grid gap-4 transition-opacity sm:grid-cols-2 lg:grid-cols-3 ${auctions.isPlaceholderData ? 'pointer-events-none opacity-50' : ''}`}
              aria-busy={auctions.isPlaceholderData}
            >
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

function ListEmpty({ status, category, onClear }: { status: AuctionListFilter; category: string | null; onClear: (filter: 'category' | 'status') => void }) {
  const message = emptyListMessage(status, category)
  const clear = message.clear

  return (
    <EmptyState
      icon={message.icon}
      title={message.title}
      action={
        clear && (
          <Button variant="secondary" onClick={() => onClear(clear)}>
            {clear === 'category' ? 'Show all categories' : 'Show all auctions'}
          </Button>
        )
      }
    >
      {message.body}
    </EmptyState>
  )
}

function AuctionCard({ auction }: { auction: Auction }) {
  const canWatch = useAuth().user?.role === 'BIDDER'

  return (
    <Link to={`/auctions/${auction.id}`} className="block transition hover:-translate-y-0.5">
      <Card className="h-full space-y-3 hover:ring-indigo-300">
        <div className="flex items-start justify-between gap-2">
          <h2 className="font-semibold leading-snug">{auction.title}</h2>
          <div className="shrink-0">
            <StatusBadge status={auction.status} />
          </div>
        </div>
        {(auction.category || canWatch) && (
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-indigo-700">{auction.category?.name}</p>
            <WatchButton auction={auction} />
          </div>
        )}
        <p className="line-clamp-2 text-sm text-slate-600">{auction.description}</p>
        <div className="flex items-end justify-between">
          <div>
            <div className="text-xs text-slate-500">{priceLabel(auction)}</div>
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
