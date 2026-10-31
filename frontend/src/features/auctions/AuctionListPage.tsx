import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { EmptyState } from '../../components/EmptyState'
import { Pagination } from '../../components/Pagination'
import { ErrorState } from '../../components/ErrorState'
import { AuctionCardsSkeleton } from '../../components/Skeleton'
import { onTabListKeyDown } from '../../components/tabs'
import { Button, Card, StatusBadge } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useNow } from '../../components/useNow'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { getEcho } from '../../lib/echo'
import { bidCount, resultLabels } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import { formatDateTime, formatFromNow } from '../../lib/time'
import type { Auction, AuctionStatus, Paginated } from '../../types/api'
import { useAuth } from '../auth/useAuth'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { useRealtimeStatus } from '../realtime/useRealtimeStatus'
import { auctionKeys, fetchAuctions, fetchCategories, type AuctionListFilter } from './api'
import { emptyListMessage } from './emptyList'
import { cardBadges, type CardBadge } from './cardBadges'
import { readListParams, SEARCH_MAX, sortOptions, writeListParams, type AuctionSort, type ListParams } from './listParams'
import { priceLabel } from './priceLabel'
import { WatchButton } from './WatchButton'

type Tab = { value: AuctionListFilter; label: string; icon: ReactNode }

const iconProps = { viewBox: '0 0 24 24', className: 'h-4 w-4', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true }

const tabs: Tab[] = [
  {
    value: 'ALL',
    label: 'All',
    icon: (
      <svg {...iconProps}>
        <rect x="3" y="3" width="7" height="7" rx="1.5" />
        <rect x="14" y="3" width="7" height="7" rx="1.5" />
        <rect x="3" y="14" width="7" height="7" rx="1.5" />
        <rect x="14" y="14" width="7" height="7" rx="1.5" />
      </svg>
    ),
  },
  {
    value: 'LIVE',
    label: 'Live',
    icon: (
      <span className="relative flex h-4 w-4 items-center justify-center" aria-hidden>
        <span className="absolute h-2.5 w-2.5 rounded-full bg-red-400 opacity-60 motion-safe:animate-ping" />
        <span className="h-2 w-2 rounded-full bg-red-600" />
      </span>
    ),
  },
  {
    value: 'SCHEDULED',
    label: 'Upcoming',
    icon: (
      <svg {...iconProps}>
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M3 10h18M8 3v4M16 3v4" />
      </svg>
    ),
  },
  {
    value: 'COMPLETED',
    label: 'Finished',
    icon: (
      <svg {...iconProps}>
        <path d="M5 21V4M5 4h11l-2 4 2 4H5" />
      </svg>
    ),
  },
]

const watchingTab: Tab = {
  value: 'WATCHING',
  label: 'Watching',
  icon: (
    <svg {...iconProps}>
      <path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z" />
    </svg>
  ),
}

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
  // Filters live in the URL, so "back" from an auction returns to the same view.
  const [searchParams, setSearchParams] = useSearchParams()
  const { status: selected, category, search, sort, page } = readListParams(searchParams)
  // The watchlist belongs to a signed-in bidder; after logging out, fall back to all auctions.
  const status = selected === 'WATCHING' && !isBidder ? 'ALL' : selected
  // Any filter change starts again from page 1, unless a page is given.
  const setFilters = (next: Partial<ListParams>) =>
    setSearchParams(writeListParams({ status, category, search, sort, page: 1, ...next }), { replace: true })
  const params: ListParams = { status, category, search, sort, page }
  const queryClient = useQueryClient()
  const realtime = useRealtimeStatus(() => queryClient.invalidateQueries({ queryKey: auctionKeys.all }))
  const categories = useQuery({ queryKey: auctionKeys.categories, queryFn: fetchCategories, staleTime: 5 * 60_000 })

  const auctions = useQuery({
    queryKey: auctionKeys.list(params),
    queryFn: () => fetchAuctions(params),
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
      <div className="space-y-5">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Auctions</h1>
          <RealtimeBadge status={realtime} />
        </div>

        {/* The one place to choose what to show: underline tabs, scrollable on narrow screens. */}
        <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          <div className="flex min-w-max gap-6 border-b border-slate-200" role="tablist" aria-label="Show auctions" onKeyDown={onTabListKeyDown}>
            {visibleTabs.map((tab) => {
              const active = status === tab.value
              return (
                <button
                  key={tab.value}
                  type="button"
                  role="tab"
                  aria-selected={active}
                  tabIndex={active ? 0 : -1}
                  onClick={() => setFilters({ status: tab.value })}
                  className={`-mb-px flex items-center gap-2 border-b-2 px-1 pb-3 text-sm font-medium transition ${
                    active ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
                  }`}
                >
                  {tab.icon}
                  {tab.label}
                </button>
              )
            })}
          </div>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <SearchBox value={search} onSearch={(value) => setFilters({ search: value })} />
          <div className="flex gap-3">
            <select
              aria-label="Category"
              className="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm sm:flex-none"
              value={category ?? ''}
              onChange={(e) => setFilters({ category: e.target.value || null })}
            >
              <option value="">All categories</option>
              {categories.data?.map((c) => (
                <option key={c.slug} value={c.slug}>
                  {c.name} ({c.auctions_count ?? 0})
                </option>
              ))}
            </select>
            <select
              aria-label="Sort by"
              className="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm sm:flex-none"
              value={sort}
              onChange={(e) => setFilters({ sort: e.target.value as AuctionSort })}
            >
              {sortOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {auctions.isPending && <AuctionCardsSkeleton />}
      {auctions.isError && (
        <ErrorState title="Could not load auctions" onRetry={() => auctions.refetch()}>
          {friendlyMessage(parseApiError(auctions.error))}
        </ErrorState>
      )}

      {auctions.data && (
        <>
          {auctions.data.data.length === 0 ? (
            <ListEmpty
              status={status}
              category={category}
              search={search}
              onClear={(filter) => setFilters(filter === 'search' ? { search: '' } : filter === 'category' ? { category: null } : { status: 'ALL' })}
            />
          ) : (
            // While another tab or page loads, the previous results stay visible but faded.
            <div
              className={`grid gap-4 transition-opacity sm:grid-cols-2 lg:grid-cols-3 ${auctions.isPlaceholderData ? 'pointer-events-none opacity-50' : ''}`}
              aria-busy={auctions.isPlaceholderData}
            >
              {auctions.data.data.map((auction) => (
                // A status tab already says the status: the badge would repeat it on every card.
                <AuctionCard key={auction.id} auction={auction} showStatus={status === 'ALL' || status === 'WATCHING'} />
              ))}
            </div>
          )}

          {auctions.data.data.length > 0 && (
            <Pagination
              page={auctions.data.meta.current_page}
              lastPage={auctions.data.meta.last_page}
              perPage={auctions.data.meta.per_page}
              total={auctions.data.meta.total}
              noun={['auction', 'auctions']}
              onChange={(next) => setFilters({ page: next })}
            />
          )}
        </>
      )}
    </div>
  )
}

function ListEmpty({
  status,
  category,
  search,
  onClear,
}: {
  status: AuctionListFilter
  category: string | null
  search: string
  onClear: (filter: 'search' | 'category' | 'status') => void
}) {
  const message = emptyListMessage(status, category, search)
  const clear = message.clear

  return (
    <EmptyState
      icon={message.icon}
      title={message.title}
      action={
        clear && (
          <Button variant="secondary" onClick={() => onClear(clear)}>
            {clear === 'search' ? 'Clear search' : clear === 'category' ? 'Show all categories' : 'Show all auctions'}
          </Button>
        )
      }
    >
      {message.body}
    </EmptyState>
  )
}

const badgeTones: Record<CardBadge['tone'], string> = {
  good: 'bg-emerald-100 text-emerald-800',
  warning: 'bg-amber-100 text-amber-900',
  urgent: 'bg-red-100 text-red-800',
}

/**
 * Searches as you type, once typing pauses, so each key press is not a request.
 * Enter searches at once; the × clears.
 */
function SearchBox({ value, onSearch }: { value: string; onSearch: (value: string) => void }) {
  const [draft, setDraft] = useState(value)
  const [synced, setSynced] = useState(value)

  // The URL changed from elsewhere (back button, "Clear search"): show that instead.
  if (value !== synced) {
    setSynced(value)
    setDraft(value)
  }

  // The parent passes a new function on every render; keep the latest without restarting the timer.
  const onSearchRef = useRef(onSearch)
  useEffect(() => {
    onSearchRef.current = onSearch
  })

  useEffect(() => {
    if (draft.trim() === value.trim()) {
      return
    }
    const timer = window.setTimeout(() => onSearchRef.current(draft), 300)
    return () => window.clearTimeout(timer)
  }, [draft, value])

  return (
    <form
      role="search"
      className="relative flex-1"
      onSubmit={(e) => {
        e.preventDefault()
        onSearch(draft)
      }}
    >
      <svg viewBox="0 0 24 24" className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden>
        <circle cx="11" cy="11" r="7" />
        <path d="M20 20l-3.5-3.5" />
      </svg>
      <input
        type="search"
        aria-label="Search auctions"
        placeholder="Search auctions by title"
        maxLength={SEARCH_MAX}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        className="block w-full rounded-md border border-slate-300 bg-white py-2 pr-9 pl-9 text-sm shadow-sm focus:ring-2 focus:ring-indigo-200 focus:outline-none [&::-webkit-search-cancel-button]:hidden"
      />
      {draft && (
        <button
          type="button"
          aria-label="Clear search"
          onClick={() => {
            setDraft('')
            onSearch('')
          }}
          className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 text-slate-400 hover:text-slate-700"
        >
          <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden>
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      )}
    </form>
  )
}

function AuctionCard({ auction, showStatus }: { auction: Auction; showStatus: boolean }) {
  const canWatch = useAuth().user?.role === 'BIDDER'
  // Handed to the detail page so its back button returns to these filters.
  const listSearch = useLocation().search
  // Every 15 s is enough for "Ending soon" to appear on time.
  const badges = cardBadges(auction, useNow(15_000))

  return (
    <Link to={`/auctions/${auction.id}`} state={{ listSearch }} className="block transition hover:-translate-y-0.5">
      <Card className="h-full space-y-3 hover:ring-indigo-300">
        <div className="flex items-start justify-between gap-2">
          <h2 className="font-semibold leading-snug">{auction.title}</h2>
          {showStatus && (
            <div className="shrink-0">
              <StatusBadge status={auction.status} />
            </div>
          )}
        </div>
        {(auction.category || canWatch) && (
          <div className="flex items-center justify-between gap-2">
            <p className="text-xs font-medium uppercase tracking-wide text-indigo-700">{auction.category?.name}</p>
            <WatchButton auction={auction} />
          </div>
        )}
        {badges.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {badges.map((badge) => (
              <span key={badge.text} className={`rounded-full px-2 py-0.5 text-xs font-semibold ${badgeTones[badge.tone]}`}>
                {badge.text}
              </span>
            ))}
          </div>
        )}
        <p className="line-clamp-2 text-sm text-slate-600">{auction.description}</p>
        <div className="flex items-end justify-between">
          <div>
            <div className="text-xs text-slate-500">{priceLabel(auction)}</div>
            <div className="text-lg font-bold">{formatRs(auction.current_price ?? auction.starting_price)}</div>
            <div className="text-xs text-slate-500">{bidCount(auction.bids_count)}</div>
          </div>
          <div className="text-right">
            {auction.status === 'LIVE' && <Countdown target={auction.end_at} label="Ends in" />}
            {auction.status === 'SCHEDULED' && <Countdown target={auction.start_at} label="Starts in" endedText="Starting…" />}
            {auction.status === 'COMPLETED' && auction.result && (
              <>
                <span className="block text-sm font-medium text-violet-700">{resultLabels[auction.result]}</span>
                <ClosedAgo label="Ended" iso={auction.ended_at ?? auction.end_at} />
              </>
            )}
            {auction.status === 'CANCELLED' && (
              <>
                <span className="block text-sm font-medium text-red-700">Cancelled</span>
                {auction.cancelled_at && <ClosedAgo label="Cancelled" iso={auction.cancelled_at} />}
              </>
            )}
          </div>
        </div>
      </Card>
    </Link>
  )
}

/** "Ended 3 days ago" under a closed auction's result, with the exact time on hover. */
function ClosedAgo({ label, iso }: { label: string; iso: string }) {
  const now = useNow(60_000)

  return (
    <time dateTime={iso} title={formatDateTime(iso)} className="block text-xs text-slate-500">
      {label} {formatFromNow(iso, now)}
    </time>
  )
}
