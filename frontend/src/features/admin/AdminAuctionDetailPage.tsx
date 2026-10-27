import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { DashboardSkeleton, RowsSkeleton } from '../../components/Skeleton'
import { Alert, Button, Card, StatusBadge } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { getEcho } from '../../lib/echo'
import { resultLabels } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import { formatDuration } from '../../lib/time'
import { AUCTION_EVENTS } from '../realtime/auctionEvents'
import { adminKeys, cancelAuction, fetchAdminAuction, fetchAdminBids, scheduleAuction } from './api'
import { AuditLog } from './AuditLog'

export function AdminAuctionDetailPage() {
  const id = Number(useParams().id)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const toast = useToast()
  const [tab, setTab] = useState<'audit' | 'bids'>('audit')
  const [cancelReason, setCancelReason] = useState<string | null>(null)
  const auction = useQuery({ queryKey: adminKeys.detail(id), queryFn: () => fetchAdminAuction(id) })
  useDocumentTitle(auction.data ? `#${auction.data.id} ${auction.data.title}` : `Auction #${id}`)

  // Live: any change to this auction refreshes the admin view.
  useEffect(() => {
    const echo = getEcho()
    if (!echo) {
      return
    }
    const channel = echo.channel(`auction.${id}`)
    for (const name of AUCTION_EVENTS) {
      channel.listen(`.${name}`, () => {
        queryClient.invalidateQueries({ queryKey: adminKeys.detail(id) })
        queryClient.invalidateQueries({ queryKey: ['admin', 'events', id] })
        queryClient.invalidateQueries({ queryKey: adminKeys.bids(id) })
      })
    }
    return () => echo.leaveChannel(`auction.${id}`)
  }, [id, queryClient])

  const onActionSuccess = (message: string) => {
    queryClient.invalidateQueries({ queryKey: adminKeys.all })
    toast.push({ tone: 'success', title: message })
  }
  const onActionError = (e: unknown) => toast.push({ tone: 'error', title: 'Action failed', body: friendlyMessage(parseApiError(e)) })

  const schedule = useMutation({ mutationFn: () => scheduleAuction(id), onSuccess: () => onActionSuccess('Auction scheduled'), onError: onActionError })
  const cancel = useMutation({
    mutationFn: (reason: string | null) => cancelAuction(id, reason),
    onSuccess: () => {
      setCancelReason(null)
      onActionSuccess('Auction cancelled')
    },
    onError: onActionError,
  })

  if (auction.isPending) {
    return <DashboardSkeleton tiles={4} label="Loading auction" />
  }
  if (auction.isError) {
    return <Alert>{friendlyMessage(parseApiError(auction.error))}</Alert>
  }

  const a = auction.data
  const cancellable = ['DRAFT', 'SCHEDULED', 'LIVE'].includes(a.status)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to="/admin/auctions" className="text-sm text-indigo-600 hover:underline">← All auctions</Link>
          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1 className="text-2xl font-bold">#{a.id} {a.title}</h1>
            <StatusBadge status={a.status} />
          </div>
          <p className="text-xs font-medium uppercase tracking-wide text-indigo-700">{a.category?.name ?? 'No category'}</p>
          {a.payment && (
            <p className="mt-1 text-sm">
              Payment:{' '}
              {a.payment.status === 'PAID' ? (
                <span className="font-medium text-emerald-700">
                  Paid {formatRs(a.payment.amount)} by {a.payment.card_brand} •••• {a.payment.card_last4} (receipt {a.payment.reference})
                </span>
              ) : a.payment.status === 'FAILED' ? (
                <span className="font-medium text-red-700">Last attempt declined ({a.payment.failure_reason}); awaiting payment</span>
              ) : (
                <span className="font-medium text-amber-700">Awaiting payment of {formatRs(a.payment.amount)}</span>
              )}
            </p>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {a.status !== 'DRAFT' && <Button variant="secondary" onClick={() => navigate(`/auctions/${a.id}`)}>View as bidder</Button>}
          {a.status === 'DRAFT' && <Button variant="secondary" onClick={() => navigate(`/admin/auctions/${a.id}/edit`)}>Edit</Button>}
          {a.status === 'DRAFT' && <Button busy={schedule.isPending} onClick={() => schedule.mutate()}>Schedule</Button>}
          {cancellable && cancelReason === null && (
            <Button variant="danger" onClick={() => setCancelReason('')}>Cancel auction</Button>
          )}
        </div>
      </div>

      {cancelReason !== null && (
        <Card className="space-y-3 ring-red-200">
          <p className="text-sm font-medium">Cancel this auction? Bidders will see it as cancelled. This cannot be undone.</p>
          <input
            className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            placeholder="Reason (optional, kept in the audit log)"
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
          />
          <div className="flex gap-2">
            <Button variant="danger" busy={cancel.isPending} onClick={() => cancel.mutate(cancelReason.trim() || null)}>Confirm cancellation</Button>
            <Button variant="secondary" onClick={() => setCancelReason(null)}>Keep auction</Button>
          </div>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Current price" value={formatRs(a.current_price)} />
        <Stat label="Reserve (private)" value={a.reserve_price === null ? 'No reserve' : formatRs(a.reserve_price)} />
        <Stat label="Result" value={a.result ? `${resultLabels[a.result]}${a.final_price ? ` · ${formatRs(a.final_price)}` : ''}` : '—'} />
        <Stat label="Bids" value={String(a.bids_count)} />
        <Stat label="Starts" value={new Date(a.start_at).toLocaleString()} />
        <Stat label="Ends" value={new Date(a.end_at).toLocaleString()} />
        <Stat label="Original end" value={new Date(a.original_end_at).toLocaleString()} />
        <Stat
          label="Extensions"
          value={
            a.anti_snipe_seconds === 0
              ? 'Anti-sniping off'
              : `${a.extensions_count} of ${a.max_extensions} · last ${formatDuration(a.anti_snipe_seconds)} adds ${formatDuration(a.extension_seconds)}`
          }
        />
      </div>

      <Card>
        <div className="mb-4 flex gap-2 border-b border-slate-100" role="tablist">
          {(['audit', 'bids'] as const).map((t) => (
            <button
              key={t}
              type="button"
              role="tab"
              aria-selected={tab === t}
              onClick={() => setTab(t)}
              className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${tab === t ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'}`}
            >
              {t === 'audit' ? 'Audit log' : 'Bids (full details)'}
            </button>
          ))}
        </div>
        {tab === 'audit' ? <AuditLog auctionId={id} /> : <AdminBids auctionId={id} />}
      </Card>
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card className="p-4">
      <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
      <div className="mt-1 font-semibold">{value}</div>
    </Card>
  )
}

function AdminBids({ auctionId }: { auctionId: number }) {
  const bids = useQuery({ queryKey: adminKeys.bids(auctionId), queryFn: () => fetchAdminBids(auctionId) })

  if (bids.isPending) {
    return <RowsSkeleton rows={4} label="Loading bids" />
  }
  if (!bids.data || bids.data.data.length === 0) {
    return <p className="text-sm text-slate-500">No bids.</p>
  }

  return (
    <table className="min-w-full text-sm">
      <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th className="py-2">Bidder</th>
          <th className="py-2">Type</th>
          <th className="py-2 text-right">Amount</th>
          <th className="py-2 text-right">Time</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {bids.data.data.map((bid) => (
          <tr key={bid.id}>
            <td className="py-2">{bid.bidder.name} <span className="text-slate-400">({bid.bidder.email} · {bid.bidder.pseudonym})</span></td>
            <td className="py-2">{bid.type}</td>
            <td className="py-2 text-right tabular-nums">{formatRs(bid.amount)}</td>
            <td className="py-2 text-right text-slate-500">{new Date(bid.created_at).toLocaleTimeString()}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
