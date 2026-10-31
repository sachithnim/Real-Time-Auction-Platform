import { useInfiniteQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { LoadMore } from '../../components/Pagination'
import { RowsSkeleton } from '../../components/Skeleton'
import { adminKeys, fetchAuctionEvents } from './api'
import { describeEvent, eventTitle, type AuditTone } from './auditFormat'

const types = [
  'ALL', 'AUCTION_CREATED', 'AUCTION_UPDATED', 'AUCTION_SCHEDULED', 'AUCTION_STARTED', 'BID_PLACED', 'BID_REJECTED',
  'AUTO_BID_CONFIGURED', 'AUTO_BID_PLACED', 'AUTO_BID_MAX_REACHED', 'LEADER_CHANGED', 'AUCTION_EXTENDED',
  'AUCTION_ENDED', 'WINNER_SELECTED', 'RESERVE_NOT_MET', 'AUCTION_CANCELLED',
]

const tones: Record<AuditTone, string> = {
  neutral: 'bg-slate-100 text-slate-700',
  bid: 'bg-indigo-50 text-indigo-700',
  warning: 'bg-amber-50 text-amber-800',
  danger: 'bg-red-50 text-red-700',
  success: 'bg-emerald-50 text-emerald-700',
}

/** The full audit trail of one auction, in the order it happened, in plain language. */
export function AuditLog({ auctionId }: { auctionId: number }) {
  const [type, setType] = useState('ALL')
  const events = useInfiniteQuery({
    queryKey: adminKeys.events(auctionId, type),
    queryFn: ({ pageParam }) => fetchAuctionEvents(auctionId, type, pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })
  // Every page brings the names of the people it mentions; together they cover the whole list.
  const rows = events.data?.pages.flatMap((page) => page.data) ?? []
  const users = Object.assign({}, ...(events.data?.pages.map((page) => page.meta.users) ?? []))
  const total = events.data?.pages[0].meta.total ?? 0

  return (
    <div className="space-y-3">
      <label className="text-sm text-slate-600">
        Show{' '}
        <select className="ml-1 rounded-md border border-slate-300 px-2 py-1 text-sm" value={type} onChange={(e) => setType(e.target.value)}>
          {types.map((t) => (
            <option key={t} value={t}>{t === 'ALL' ? 'All events' : eventTitle(t)}</option>
          ))}
        </select>
      </label>
      {events.isPending && <RowsSkeleton rows={6} label="Loading the audit log" />}
      {events.data && rows.length === 0 && <p className="text-sm text-slate-500">No events.</p>}
      {events.data && rows.length > 0 && (
        <ol className="space-y-2" aria-label="Audit log">
          {rows.map((event) => {
            const view = describeEvent(event, users)
            return (
              <li key={event.id} className="rounded-md border border-slate-200 bg-white p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className={`rounded px-2 py-0.5 text-xs font-semibold ${tones[view.tone]}`}>{view.title}</span>
                  <span className="text-xs text-slate-500" title={event.actor?.email}>
                    {new Date(event.created_at).toLocaleString()} · {event.actor ? event.actor.name : 'System'}
                  </span>
                </div>
                {view.summary && <p className="mt-2 text-sm text-slate-800">{view.summary}</p>}
                {view.details.length > 0 && (
                  <dl className="mt-2 grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                    {view.details.map((line) => (
                      <div key={line.label} className="flex gap-2">
                        <dt className="text-slate-500">{line.label}</dt>
                        <dd className="text-slate-800">{line.value}</dd>
                      </div>
                    ))}
                  </dl>
                )}
              </li>
            )
          })}
        </ol>
      )}
      {rows.length > 0 && (
        <p className="text-center text-xs text-slate-500">
          Showing {rows.length} of {total} {total === 1 ? 'event' : 'events'}
        </p>
      )}
      {events.hasNextPage && <LoadMore onClick={() => events.fetchNextPage()} loading={events.isFetchingNextPage} label="Load more events" />}
    </div>
  )
}
