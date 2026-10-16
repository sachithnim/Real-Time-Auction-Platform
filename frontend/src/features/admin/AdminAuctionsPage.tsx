import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Alert, Button, Card, PageLoader, StatusBadge } from '../../components/ui'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import type { AuctionStatus } from '../../types/api'
import { adminKeys, fetchAdminAuctions } from './api'

const statuses: (AuctionStatus | 'ALL')[] = ['ALL', 'DRAFT', 'SCHEDULED', 'LIVE', 'COMPLETED', 'CANCELLED']

export function AdminAuctionsPage() {
  const [status, setStatus] = useState<AuctionStatus | 'ALL'>('ALL')
  const [page, setPage] = useState(1)
  const navigate = useNavigate()
  const auctions = useQuery({
    queryKey: adminKeys.list(status, page),
    queryFn: () => fetchAdminAuctions(status, page),
    placeholderData: keepPreviousData,
    refetchInterval: 15_000,
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">Manage auctions</h1>
        <div className="flex items-center gap-3">
          <label className="text-sm text-slate-600">
            Status{' '}
            <select
              className="ml-1 rounded-md border border-slate-300 px-2 py-1.5 text-sm"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as AuctionStatus | 'ALL')
                setPage(1)
              }}
            >
              {statuses.map((s) => (
                <option key={s} value={s}>{s === 'ALL' ? 'All' : s}</option>
              ))}
            </select>
          </label>
          <Button onClick={() => navigate('/admin/auctions/new')}>New auction</Button>
        </div>
      </div>

      {auctions.isPending && <PageLoader />}
      {auctions.isError && <Alert>{friendlyMessage(parseApiError(auctions.error))}</Alert>}

      {auctions.data && (
        <Card className="overflow-x-auto p-0">
          <table className="min-w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Auction</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3 text-right">Price</th>
                <th className="px-4 py-3 text-right">Reserve</th>
                <th className="px-4 py-3 text-right">Bids</th>
                <th className="px-4 py-3">Starts</th>
                <th className="px-4 py-3">Ends</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {auctions.data.data.map((a) => (
                <tr key={a.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link to={`/admin/auctions/${a.id}`} className="font-medium text-indigo-700 hover:underline">
                      #{a.id} {a.title}
                    </Link>
                  </td>
                  <td className="px-4 py-3"><StatusBadge status={a.status} /></td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatRs(a.current_price ?? a.starting_price)}</td>
                  <td className="px-4 py-3 text-right tabular-nums text-slate-500">{formatRs(a.reserve_price)}</td>
                  <td className="px-4 py-3 text-right">{a.bids_count}</td>
                  <td className="px-4 py-3 text-slate-600">{new Date(a.start_at).toLocaleString()}</td>
                  <td className="px-4 py-3 text-slate-600">{new Date(a.end_at).toLocaleString()}</td>
                </tr>
              ))}
              {auctions.data.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-slate-500">No auctions.</td>
                </tr>
              )}
            </tbody>
          </table>
        </Card>
      )}

      {auctions.data && auctions.data.meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-3">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
          <span className="text-sm text-slate-500">Page {page} of {auctions.data.meta.last_page}</span>
          <Button variant="secondary" disabled={page >= auctions.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
        </div>
      )}
    </div>
  )
}
