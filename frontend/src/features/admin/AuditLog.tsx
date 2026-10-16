import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { PageLoader } from '../../components/ui'
import { formatRs } from '../../lib/money'
import { adminKeys, fetchAuctionEvents } from './api'

const types = [
  'ALL', 'AUCTION_CREATED', 'AUCTION_UPDATED', 'AUCTION_SCHEDULED', 'AUCTION_STARTED', 'BID_PLACED', 'BID_REJECTED',
  'AUTO_BID_CONFIGURED', 'AUTO_BID_PLACED', 'AUTO_BID_MAX_REACHED', 'LEADER_CHANGED', 'AUCTION_EXTENDED',
  'AUCTION_ENDED', 'WINNER_SELECTED', 'RESERVE_NOT_MET', 'AUCTION_CANCELLED',
]

const tone = (type: string) =>
  type.includes('REJECTED') || type.includes('CANCELLED') || type.includes('NOT_MET') ? 'bg-red-50 text-red-700'
    : type.includes('BID') ? 'bg-indigo-50 text-indigo-700'
      : type.includes('WINNER') || type.includes('ENDED') ? 'bg-violet-50 text-violet-700'
        : 'bg-slate-100 text-slate-700'

const moneyKeys = /price|amount|minimum|bid$/i

function formatValue(key: string, value: unknown): string {
  if (typeof value === 'number' && moneyKeys.test(key) && !key.endsWith('_id')) {
    return formatRs(value)
  }
  if (value === null) {
    return '—'
  }
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

/** The full audit trail of one auction, in the order it happened. */
export function AuditLog({ auctionId }: { auctionId: number }) {
  const [type, setType] = useState('ALL')
  const events = useQuery({ queryKey: adminKeys.events(auctionId, type), queryFn: () => fetchAuctionEvents(auctionId, type) })

  return (
    <div className="space-y-3">
      <label className="text-sm text-slate-600">
        Show{' '}
        <select className="ml-1 rounded-md border border-slate-300 px-2 py-1 text-sm" value={type} onChange={(e) => setType(e.target.value)}>
          {types.map((t) => (
            <option key={t} value={t}>{t === 'ALL' ? 'All events' : t}</option>
          ))}
        </select>
      </label>
      {events.isPending && <PageLoader />}
      {events.data && events.data.data.length === 0 && <p className="text-sm text-slate-500">No events.</p>}
      {events.data && (
        <ol className="space-y-2" aria-label="Audit log">
          {events.data.data.map((event) => (
            <li key={event.id} className="rounded-md border border-slate-200 bg-white p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className={`rounded px-2 py-0.5 text-xs font-semibold ${tone(event.type)}`}>{event.type}</span>
                <span className="text-xs text-slate-500">
                  {new Date(event.created_at).toLocaleString()} · {event.actor ? `${event.actor.name} (${event.actor.email})` : 'System'}
                </span>
              </div>
              <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                {Object.entries(event.payload).map(([key, value]) => (
                  <div key={key} className="flex gap-1">
                    <dt className="text-slate-500">{key}:</dt>
                    <dd className="font-mono text-slate-800">{formatValue(key, value)}</dd>
                  </div>
                ))}
              </dl>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
