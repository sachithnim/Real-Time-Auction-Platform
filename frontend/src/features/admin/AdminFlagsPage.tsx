import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { TableSkeleton } from '../../components/Skeleton'
import { Alert, Button, Card } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import type { BidFlag, FlagStatus } from '../../types/api'
import { adminKeys, fetchFlags, reviewFlag } from './api'
import { describeFlag } from './flagFormat'

const tabs: { value: FlagStatus | 'ALL'; label: string }[] = [
  { value: 'OPEN', label: 'Open' },
  { value: 'REVIEWED', label: 'Reviewed' },
  { value: 'ALL', label: 'All' },
]

/** Suspicious bidding flags for review (bonus challenge: suspicious bidding detection). */
export function AdminFlagsPage() {
  useDocumentTitle('Suspicious bidding')
  const [status, setStatus] = useState<FlagStatus | 'ALL'>('OPEN')
  const flags = useQuery({
    queryKey: adminKeys.flags(status),
    queryFn: () => fetchFlags(status),
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
  })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">Suspicious bidding</h1>
          <p className="max-w-2xl text-sm text-slate-500">
            Patterns worth a second look. A flag never blocks a bid; it is a signal for you to review, not proof of wrongdoing.
          </p>
        </div>
        <div className="flex gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200" role="tablist">
          {tabs.map((tab) => (
            <button
              key={tab.value}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              onClick={() => setStatus(tab.value)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === tab.value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {tab.label}
              {tab.value === 'OPEN' && flags.data ? ` (${flags.data.meta.open_count})` : ''}
            </button>
          ))}
        </div>
      </div>

      {flags.isPending && <TableSkeleton rows={3} label="Loading flags" />}
      {flags.isError && <Alert>{friendlyMessage(parseApiError(flags.error))}</Alert>}

      {flags.data && (
        <div className="space-y-3">
          {flags.data.data.length === 0 ? (
            <p className="py-12 text-center text-slate-500">
              {status === 'OPEN' ? 'Nothing to review. No suspicious bidding has been flagged.' : 'No flags here.'}
            </p>
          ) : (
            flags.data.data.map((flag) => <FlagCard key={flag.id} flag={flag} />)
          )}
        </div>
      )}
    </div>
  )
}

function FlagCard({ flag }: { flag: BidFlag }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const { title, why, evidence } = describeFlag(flag)

  const review = useMutation({
    mutationFn: () => reviewFlag(flag.id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'flags'] })
      toast.push({ tone: 'success', title: 'Marked as reviewed', body: `${title} on ${flag.auction.title ?? `auction #${flag.auction.id}`}.` })
    },
    onError: (error) => toast.push({ tone: 'error', title: 'Not updated', body: friendlyMessage(parseApiError(error)) }),
  })

  const open = flag.status === 'OPEN'

  return (
    <Card className={`space-y-3 ${open ? 'ring-amber-300' : ''}`}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-semibold">{title}</h2>
            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${open ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>
              {open ? 'Open' : 'Reviewed'}
            </span>
          </div>
          <p className="text-sm text-slate-600">{why}</p>
        </div>
        {open && (
          <Button variant="secondary" busy={review.isPending} onClick={() => review.mutate()}>
            Mark reviewed
          </Button>
        )}
      </div>

      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-slate-500">Auction</dt>
          <dd>
            <Link to={`/admin/auctions/${flag.auction.id}`} className="font-medium text-indigo-700 hover:underline">
              #{flag.auction.id} {flag.auction.title}
            </Link>
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-slate-500">{flag.bidders.length > 1 ? 'Accounts' : 'Account'}</dt>
          <dd className="space-y-0.5">
            {flag.bidders.map((bidder) => (
              <div key={bidder.id}>
                <span className="font-medium">{bidder.name}</span> <span className="text-slate-500">{bidder.email}</span>
              </div>
            ))}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-slate-500">Evidence</dt>
          <dd>{evidence}</dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-slate-500">Seen</dt>
          <dd>
            {flag.occurrences === 1 ? 'Once' : `${flag.occurrences} times`}, last {new Date(flag.last_detected_at).toLocaleString()}
            {flag.reviewed_by && flag.reviewed_at && (
              <div className="text-xs text-slate-500">
                Reviewed by {flag.reviewed_by.name}, {new Date(flag.reviewed_at).toLocaleString()}
              </div>
            )}
          </dd>
        </div>
      </dl>
    </Card>
  )
}
