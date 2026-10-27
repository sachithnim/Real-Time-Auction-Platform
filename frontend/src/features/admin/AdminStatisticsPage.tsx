import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { DashboardSkeleton } from '../../components/Skeleton'
import { Alert, Card, StatusBadge } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { resultLabels } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import type { AuctionResult, AuctionStatus } from '../../types/api'
import { adminKeys, fetchStatistics } from './api'

const oneDecimal = new Intl.NumberFormat('en-LK', { maximumFractionDigits: 1 })

/** "50%", "66.7%", or a dash while there is nothing to measure. */
function formatPercent(value: number | null): string {
  return value === null ? '—' : `${oneDecimal.format(value)}%`
}

const statusOrder: AuctionStatus[] = ['LIVE', 'SCHEDULED', 'DRAFT', 'COMPLETED', 'CANCELLED']

const resultBars: { key: 'sold' | 'reserve_not_met' | 'no_bids'; result: AuctionResult; color: string }[] = [
  { key: 'sold', result: 'SOLD', color: 'bg-emerald-500' },
  { key: 'reserve_not_met', result: 'RESERVE_NOT_MET', color: 'bg-amber-400' },
  { key: 'no_bids', result: 'NO_BIDS', color: 'bg-slate-300' },
]

/** Platform-wide figures for admins (bonus challenge: auction statistics). */
export function AdminStatisticsPage() {
  useDocumentTitle('Statistics')
  const stats = useQuery({ queryKey: adminKeys.statistics, queryFn: fetchStatistics, refetchInterval: 30_000 })

  if (stats.isPending) {
    return <DashboardSkeleton tiles={4} label="Loading statistics" />
  }

  if (stats.isError) {
    return <Alert>{friendlyMessage(parseApiError(stats.error))}</Alert>
  }

  const s = stats.data

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Statistics</h1>
        <p className="text-sm text-slate-500">Figures across every auction on the platform. Refreshes every 30 seconds.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Tile label="Auctions" value={String(s.auctions.total)} note={`${s.auctions.by_status.LIVE} live now`} />
        <Tile label="Sell-through" value={formatPercent(s.results.sell_through_percent)} note={`${s.results.sold} of ${s.results.completed} completed sold`} />
        <Tile label="Revenue" value={formatRs(s.revenue.total)} note="Sum of final prices" />
        <Tile label="Average sale" value={formatRs(s.revenue.average)} note={`Highest ${formatRs(s.revenue.highest)}`} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Auctions by status">
          <ul className="space-y-2">
            {statusOrder.map((status) => (
              <li key={status} className="flex items-center justify-between">
                <StatusBadge status={status} />
                <span className="font-semibold tabular-nums">{s.auctions.by_status[status]}</span>
              </li>
            ))}
          </ul>
        </Section>

        <Section title="Completed auctions">
          <ul className="space-y-3">
            {resultBars.map(({ key, result, color }) => {
              const count = s.results[key]
              const share = s.results.completed > 0 ? (count / s.results.completed) * 100 : 0
              return (
                <li key={key} className="space-y-1">
                  <div className="flex justify-between text-sm">
                    <span>{resultLabels[result]}</span>
                    <span className="font-semibold tabular-nums">{count}</span>
                  </div>
                  <div className="h-2 rounded-full bg-slate-100" role="presentation">
                    <div className={`h-2 rounded-full ${color}`} style={{ width: `${share}%` }} />
                  </div>
                </li>
              )
            })}
          </ul>
        </Section>

        <Section title="Bidding">
          <dl className="space-y-1 text-sm">
            <Row label="Accepted bids" value={String(s.bids.total)} />
            <Row label="Manual / automatic" value={`${s.bids.manual} / ${s.bids.auto}`} />
            <Row label="Bidders" value={String(s.bids.bidders)} />
            <Row label="Auctions with bids" value={String(s.bids.auctions_with_bids)} />
            <Row label="Average bids per auction" value={s.bids.average_per_auction === null ? '—' : oneDecimal.format(s.bids.average_per_auction)} />
          </dl>
        </Section>

        <Section title="Anti-sniping">
          <dl className="space-y-1 text-sm">
            <Row label="Auctions extended" value={String(s.anti_sniping.extended_auctions)} />
            <Row label="Extensions in total" value={String(s.anti_sniping.total_extensions)} />
          </dl>
        </Section>
      </div>

      <Section title="Most active bidders">
        {s.top_bidders.length === 0 ? (
          <p className="text-sm text-slate-500">No bids yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="py-2 pr-4">Bidder</th>
                  <th className="py-2 pr-4 text-right">Bids</th>
                  <th className="py-2 pr-4 text-right">Auctions</th>
                  <th className="py-2 text-right">Wins</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {s.top_bidders.map((bidder) => (
                  <tr key={bidder.id}>
                    <td className="py-2 pr-4">
                      <div className="font-medium">{bidder.name}</div>
                      <div className="text-xs text-slate-500">{bidder.email}</div>
                    </td>
                    <td className="py-2 pr-4 text-right tabular-nums">{bidder.bids}</td>
                    <td className="py-2 pr-4 text-right tabular-nums">{bidder.auctions}</td>
                    <td className="py-2 text-right tabular-nums">{bidder.wins}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>
    </div>
  )
}

function Tile({ label, value, note }: { label: string; value: string; note: string }) {
  return (
    <Card className="space-y-1">
      <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
      <div className="text-2xl font-bold tabular-nums">{value}</div>
      <div className="text-xs text-slate-500">{note}</div>
    </Card>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card>
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children}
    </Card>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <dt className="text-slate-500">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  )
}
