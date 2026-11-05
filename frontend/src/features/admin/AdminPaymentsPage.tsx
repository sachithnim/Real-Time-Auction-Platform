import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { Pagination } from '../../components/Pagination'
import { TableSkeleton } from '../../components/Skeleton'
import { stackedTable } from '../../components/stackedTable'
import { onTabListKeyDown } from '../../components/tabs'
import { Alert, Button, Card } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import type { AdminPayment, PaymentCounts, PaymentStatus } from '../../types/api'
import { adminKeys, fetchPayments } from './api'
import { paymentDetails } from './paymentFormat'

const tabs: { value: PaymentStatus | 'ALL'; label: string; count: keyof PaymentCounts }[] = [
  { value: 'ALL', label: 'All', count: 'all' },
  { value: 'PENDING', label: 'Waiting', count: 'pending' },
  { value: 'FAILED', label: 'Declined', count: 'failed' },
  { value: 'PAID', label: 'Paid', count: 'paid' },
]

const statusBadges: Record<PaymentStatus, { text: string; className: string }> = {
  PENDING: { text: 'Waiting', className: 'bg-amber-100 text-amber-900' },
  FAILED: { text: 'Declined', className: 'bg-red-100 text-red-800' },
  PAID: { text: 'Paid', className: 'bg-emerald-100 text-emerald-800' },
}

/** Every winner's payment: who has paid and who still owes. */
export function AdminPaymentsPage() {
  useDocumentTitle('Payments')
  const [status, setStatus] = useState<PaymentStatus | 'ALL'>('ALL')
  const [draft, setDraft] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const payments = useQuery({
    queryKey: adminKeys.payments(status, search, page),
    queryFn: () => fetchPayments(status, search, page),
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
  })
  const counts = payments.data?.counts

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Payments</h1>
        <p className="text-sm text-slate-500">Declined and waiting payments come first.</p>
      </div>

      {counts && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Card>
            <div className="text-xs uppercase tracking-wide text-slate-500">Received</div>
            <div className="text-2xl font-bold tabular-nums">{formatRs(counts.paid_total)}</div>
            <div className="text-xs text-slate-500">{counts.paid} paid</div>
          </Card>
          <Card>
            <div className="text-xs uppercase tracking-wide text-slate-500">Still owed</div>
            <div className="text-2xl font-bold tabular-nums">{formatRs(counts.owed_total)}</div>
            <div className="text-xs text-slate-500">
              {counts.pending} waiting · {counts.failed} declined
            </div>
          </Card>
        </div>
      )}

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex gap-1 self-start rounded-lg bg-white p-1 ring-1 ring-slate-200" role="tablist" aria-label="Show payments" onKeyDown={onTabListKeyDown}>
          {tabs.map((tab) => (
            <button
              key={tab.value}
              type="button"
              role="tab"
              aria-selected={status === tab.value}
              tabIndex={status === tab.value ? 0 : -1}
              onClick={() => {
                setStatus(tab.value)
                setPage(1)
              }}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === tab.value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'}`}
            >
              {tab.label}
              {counts ? ` (${counts[tab.count]})` : ''}
            </button>
          ))}
        </div>
        <form
          role="search"
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setSearch(draft)
            setPage(1)
          }}
        >
          <input
            type="search"
            aria-label="Search payments"
            placeholder="Auction, bidder or receipt"
            maxLength={100}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            className="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:ring-2 focus:ring-indigo-200 focus:outline-none sm:w-64"
          />
          <Button type="submit" variant="secondary">
            Search
          </Button>
        </form>
      </div>

      {payments.isPending && <TableSkeleton rows={5} label="Loading payments" />}
      {payments.isError && <Alert>{friendlyMessage(parseApiError(payments.error))}</Alert>}

      {payments.data && (
        <div className="space-y-3">
          <Card className={`overflow-hidden p-0 transition-opacity ${payments.isPlaceholderData ? 'opacity-50' : ''}`}>
            <table className={stackedTable.table}>
              <thead className={stackedTable.head}>
                <tr>
                  <th className="px-4 py-3">Auction</th>
                  <th className="px-4 py-3">Winner</th>
                  <th className="px-4 py-3 text-right">Amount</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Details</th>
                </tr>
              </thead>
              <tbody className={stackedTable.body}>
                {payments.data.data.length === 0 ? (
                  <tr>
                    <td colSpan={5} className={stackedTable.emptyCell}>
                      {search ? `No payments match “${search}”.` : 'No payments here yet. One opens when an auction is sold.'}
                    </td>
                  </tr>
                ) : (
                  payments.data.data.map((payment) => <PaymentRow key={payment.id} payment={payment} />)
                )}
              </tbody>
            </table>
          </Card>
          {payments.data.data.length > 0 && (
            <Pagination
              page={payments.data.meta.current_page}
              lastPage={payments.data.meta.last_page}
              perPage={payments.data.meta.per_page}
              total={payments.data.meta.total}
              noun={['payment', 'payments']}
              onChange={setPage}
            />
          )}
        </div>
      )}
    </div>
  )
}

function PaymentRow({ payment }: { payment: AdminPayment }) {
  const badge = statusBadges[payment.status]

  return (
    <tr className={stackedTable.row}>
      <td className={stackedTable.titleCell}>
        <Link to={`/admin/auctions/${payment.auction.id}`} className="font-medium text-indigo-700 hover:underline">
          #{payment.auction.id} {payment.auction.title}
        </Link>
      </td>
      <td className={stackedTable.cell} data-label="Winner">
        <span>
          {payment.bidder.name}
          <span className="block text-xs text-slate-500">{payment.bidder.email}</span>
        </span>
      </td>
      <td className={`${stackedTable.cell} font-semibold tabular-nums md:text-right`} data-label="Amount">
        {formatRs(payment.amount)}
      </td>
      <td className={stackedTable.cell} data-label="Status">
        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${badge.className}`}>{badge.text}</span>
      </td>
      <td className={`${stackedTable.cell} text-slate-600`} data-label="Details">
        <span>{paymentDetails(payment)}</span>
      </td>
    </tr>
  )
}
