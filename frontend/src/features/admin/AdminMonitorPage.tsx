import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { Alert, Card, PageLoader } from '../../components/ui'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRemaining, inAntiSnipeWindow, remaining } from '../../lib/countdown'
import { formatRs } from '../../lib/money'
import { serverNow } from '../../lib/serverClock'
import type { MonitorAuction } from '../../types/api'
import { RealtimeBadge } from '../realtime/RealtimeBadge'
import { rejectionReason } from './monitor'
import { useAdminMonitor } from './useAdminMonitor'

/** Every running auction, updated as bids arrive (bonus challenge: admin live monitoring). */
export function AdminMonitorPage() {
  const { monitor, realtime } = useAdminMonitor()

  if (monitor.isPending) {
    return <PageLoader />
  }

  if (monitor.isError) {
    return <Alert>{friendlyMessage(parseApiError(monitor.error))}</Alert>
  }

  const { auctions, recent_rejections: rejections } = monitor.data
  const recentBids = auctions.reduce((sum, auction) => sum + auction.recent_bids, 0)
  const reserveNotMet = auctions.filter((auction) => !auction.reserve_met).length

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Live monitor</h1>
        <div className="flex flex-wrap items-center gap-3">
          <p className="text-sm text-slate-500">Every running auction, updated as bids arrive.</p>
          <RealtimeBadge status={realtime} />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Tile label="Live auctions" value={auctions.length} />
        <Tile label="Bids in the last 5 minutes" value={recentBids} />
        <Tile label="Reserve not met yet" value={reserveNotMet} />
      </div>

      <Card className="overflow-x-auto p-0">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-3">Auction</th>
              <th className="px-4 py-3 text-right">Price</th>
              <th className="px-4 py-3">Reserve</th>
              <th className="px-4 py-3">Leader</th>
              <th className="px-4 py-3 text-right">Bids</th>
              <th className="px-4 py-3 text-right">Auto-bids</th>
              <th className="px-4 py-3 text-right">Ends in</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {auctions.map((auction) => (
              <AuctionRow key={auction.id} auction={auction} />
            ))}
            {auctions.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-8 text-center text-slate-500">No auctions are live right now.</td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>

      <Card>
        <h2 className="mb-3 font-semibold">Recent rejected bids</h2>
        {rejections.length === 0 ? (
          <p className="text-sm text-slate-500">No rejected bids.</p>
        ) : (
          <ul className="divide-y divide-slate-100 text-sm">
            {rejections.map((rejection) => (
              <li key={rejection.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                <div className="min-w-0">
                  <span className="font-medium">{rejection.bidder?.name ?? 'Unknown bidder'}</span>{' '}
                  <span className="text-slate-500">
                    {rejection.action === 'AUTO_BID' ? 'set a maximum of' : 'bid'} {formatRs(rejection.amount)} on{' '}
                    <Link to={`/admin/auctions/${rejection.auction_id}`} className="text-indigo-700 hover:underline">
                      {rejection.auction_title ?? `auction #${rejection.auction_id}`}
                    </Link>
                  </span>
                </div>
                <div className="flex items-center gap-3 text-xs">
                  <span className="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-800">{rejectionReason(rejection.reason)}</span>
                  <span className="tabular-nums text-slate-500">{new Date(rejection.created_at).toLocaleTimeString()}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function AuctionRow({ auction }: { auction: MonitorAuction }) {
  return (
    <tr className="hover:bg-slate-50">
      <td className="px-4 py-3">
        <Link to={`/admin/auctions/${auction.id}`} className="font-medium text-indigo-700 hover:underline">
          #{auction.id} {auction.title}
        </Link>
        {auction.max_extensions > 0 && (
          <div className="text-xs text-slate-500">Extended {auction.extensions_count} of {auction.max_extensions}</div>
        )}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {auction.current_price === null ? (
          <span className="text-slate-500">Starts at {formatRs(auction.starting_price)}</span>
        ) : (
          <span className="font-semibold">{formatRs(auction.current_price)}</span>
        )}
      </td>
      <td className="px-4 py-3">
        {auction.reserve_price === null ? (
          <span className="text-slate-500">No reserve</span>
        ) : (
          <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${auction.reserve_met ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
            {formatRs(auction.reserve_price)} · {auction.reserve_met ? 'met' : 'not met'}
          </span>
        )}
      </td>
      <td className="px-4 py-3">
        {auction.leader === null ? (
          <span className="text-slate-500">No bids yet</span>
        ) : (
          <>
            <div className="font-medium">{auction.leader.name}</div>
            <div className="text-xs text-slate-500">{auction.leader.email} · shown as {auction.leader.pseudonym}</div>
          </>
        )}
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        <div>{auction.bids_count}</div>
        <div className="text-xs text-slate-500">{auction.recent_bids} in 5 min</div>
      </td>
      <td className="px-4 py-3 text-right tabular-nums">{auction.active_auto_bids}</td>
      <td className="px-4 py-3 text-right">
        <TimeLeft endAt={auction.end_at} antiSnipeSeconds={auction.anti_snipe_seconds} />
      </td>
    </tr>
  )
}

/** A compact countdown on the server's clock; red inside the anti-sniping window. */
function TimeLeft({ endAt, antiSnipeSeconds }: { endAt: string; antiSnipeSeconds: number }) {
  const [now, setNow] = useState(() => serverNow())

  useEffect(() => {
    const timer = window.setInterval(() => setNow(serverNow()), 1_000)
    return () => window.clearInterval(timer)
  }, [])

  const left = remaining(endAt, now)
  const urgent = inAntiSnipeWindow(left, antiSnipeSeconds)

  return (
    <span className={`font-mono tabular-nums ${urgent ? 'font-semibold text-red-600' : ''}`}>
      {left.ended ? 'Closing…' : formatRemaining(left)}
    </span>
  )
}

function Tile({ label, value }: { label: string; value: number }) {
  return (
    <Card className="space-y-1">
      <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</div>
      <div className="text-2xl font-bold tabular-nums">{value}</div>
    </Card>
  )
}
