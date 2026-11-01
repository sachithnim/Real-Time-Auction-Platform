import { useQuery } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ErrorState } from '../../components/ErrorState'
import { DashboardSkeleton } from '../../components/Skeleton'
import { Card } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useNow } from '../../components/useNow'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { bidCount } from '../../lib/labels'
import { formatRs } from '../../lib/money'
import { formatDateTime, formatFromNow } from '../../lib/time'
import { useAuth } from '../auth/useAuth'
import { eventTitle } from './auditFormat'
import { dashboardKey, fetchDashboard, type AdminDashboard } from './dashboardApi'

/** The admin home: what is live, what happened lately, and what needs a decision. */
export function AdminDashboardPage() {
  useDocumentTitle('Dashboard')
  const { user } = useAuth()
  const now = useNow(15_000)
  const dashboard = useQuery({ queryKey: dashboardKey, queryFn: fetchDashboard, refetchInterval: 30_000 })

  if (dashboard.isPending) {
    return <DashboardSkeleton tiles={4} label="Loading the dashboard" />
  }
  if (dashboard.isError) {
    return (
      <ErrorState title="Could not load the dashboard" onRetry={() => dashboard.refetch()}>
        {friendlyMessage(parseApiError(dashboard.error))}
      </ErrorState>
    )
  }

  const d = dashboard.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{greeting(new Date(now).getHours())}{user?.first_name ? `, ${user.first_name}` : ''}</h1>
          <p className="text-sm text-slate-500">Here is what is happening across LiveBid.</p>
        </div>
        <Link to="/admin/auctions/new" className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
          <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" aria-hidden>
            <path d="M12 5v14M5 12h14" />
          </svg>
          New auction
        </Link>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Tile label="Live now" value={d.auctions.live} to="/admin/monitor" hint="Open the live monitor" accent="live" />
        <Tile label="Scheduled" value={d.auctions.scheduled} to="/admin/auctions?status=scheduled" hint="Waiting to start" />
        <Tile label="Drafts" value={d.auctions.draft} to="/admin/auctions?status=draft" hint="Not yet scheduled" />
        <Tile
          label="Bids · last 24 h"
          value={d.last_24_hours.bids}
          to="/admin/statistics"
          hint={`${d.last_24_hours.active_bidders} ${d.last_24_hours.active_bidders === 1 ? 'bidder' : 'bidders'} active`}
        />
      </div>

      <Attention attention={d.attention} />

      <div className="grid gap-6 lg:grid-cols-2">
        <Section title="Ending soon" link={{ to: '/admin/monitor', label: 'Live monitor' }} empty="No auctions are live right now.">
          {d.ending_soon.map((a) => (
            <Row
              key={a.id}
              id={a.id}
              title={a.title}
              meta={`${a.leader ? `Leading: ${a.leader}` : 'No bids yet'} · ${bidCount(a.bids_count)}`}
              value={formatRs(a.current_price)}
              when={<time dateTime={a.end_at} title={formatDateTime(a.end_at)}>ends {formatFromNow(a.end_at, now)}</time>}
            />
          ))}
        </Section>

        <Section title="Starting soon" link={{ to: '/admin/auctions?status=scheduled', label: 'All scheduled' }} empty="Nothing is scheduled.">
          {d.starting_soon.map((a) => (
            <Row
              key={a.id}
              id={a.id}
              title={a.title}
              meta={`Starts at ${formatRs(a.starting_price)}`}
              warning={a.images_count === 0 ? 'No photos' : undefined}
              when={<time dateTime={a.start_at} title={formatDateTime(a.start_at)}>starts {formatFromNow(a.start_at, now)}</time>}
            />
          ))}
        </Section>

        <Section title="Drafts to finish" link={{ to: '/admin/auctions?status=draft', label: 'All drafts' }} empty="No drafts. Everything is scheduled.">
          {d.drafts.map((a) => (
            <Row
              key={a.id}
              id={a.id}
              title={a.title}
              meta={`Edited ${formatFromNow(a.updated_at, now)}`}
              warning={a.images_count === 0 ? 'No photos' : Date.parse(a.start_at) <= now ? 'Start time passed' : undefined}
            />
          ))}
        </Section>

        <Section title="Recent activity" empty="Nothing has happened yet.">
          {d.recent_activity.map((event) => (
            <li key={event.id} className="flex items-start justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-slate-900">
                  {eventTitle(event.type)}
                  {event.amount !== null && <span className="text-slate-600"> · {formatRs(event.amount)}</span>}
                </p>
                <p className="truncate text-xs text-slate-500">
                  <Link to={`/admin/auctions/${event.auction.id}`} className="hover:text-indigo-700 hover:underline">
                    {event.auction.title ?? `Auction #${event.auction.id}`}
                  </Link>
                  {' · '}
                  {event.actor ?? 'System'}
                </p>
              </div>
              <time dateTime={event.created_at} title={formatDateTime(event.created_at)} className="shrink-0 text-xs text-slate-500">
                {formatFromNow(event.created_at, now)}
              </time>
            </li>
          ))}
        </Section>
      </div>

      <Card>
        <h2 className="mb-3 font-semibold">Last 24 hours</h2>
        <dl className="grid gap-4 sm:grid-cols-3">
          <Figure label="Sold for" value={formatRs(d.last_24_hours.sold_value)} />
          <Figure label="Auctions ended" value={String(d.last_24_hours.auctions_ended)} />
          <Figure label="New bidders" value={String(d.last_24_hours.new_bidders)} />
        </dl>
      </Card>
    </div>
  )
}

function greeting(hour: number): string {
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

function Tile({ label, value, to, hint, accent }: { label: string; value: number; to: string; hint: string; accent?: 'live' }) {
  return (
    <Link to={to} className="group block">
      <Card className="h-full transition group-hover:ring-indigo-300">
        <p className="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-500 uppercase">
          {accent === 'live' && value > 0 && <span className="h-2 w-2 rounded-full bg-red-600 motion-safe:animate-pulse" aria-hidden />}
          {label}
        </p>
        <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{value}</p>
        <p className="mt-1 text-xs text-slate-500 group-hover:text-indigo-700">{hint} →</p>
      </Card>
    </Link>
  )
}

/** Things only an admin can resolve. Calm and green when there are none. */
function Attention({ attention }: { attention: AdminDashboard['attention'] }) {
  const unpaid = attention.unpaid_wins + attention.unpaid_sold
  const items = [
    attention.open_flags > 0 && { to: '/admin/flags', text: `${attention.open_flags} suspicious bidding ${attention.open_flags === 1 ? 'flag' : 'flags'} to review` },
    unpaid > 0 && { to: '/admin/auctions?status=completed', text: `${unpaid} sold ${unpaid === 1 ? 'auction is' : 'auctions are'} waiting for payment` },
  ].filter((item): item is { to: string; text: string } => Boolean(item))

  if (items.length === 0) {
    return (
      <div className="flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200" role="status">
        <svg viewBox="0 0 24 24" className="h-5 w-5 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M8 12.5l3 3 5-6M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z" />
        </svg>
        Nothing needs your attention right now.
      </div>
    )
  }

  return (
    <div className="rounded-lg bg-amber-50 px-4 py-3 ring-1 ring-amber-200" role="status">
      <p className="text-sm font-semibold text-amber-900">Needs your attention</p>
      <ul className="mt-1 space-y-1">
        {items.map((item) => (
          <li key={item.to}>
            <Link to={item.to} className="text-sm text-amber-900 underline-offset-2 hover:underline">
              {item.text} →
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

function Section({ title, link, empty, children }: { title: string; link?: { to: string; label: string }; empty: string; children: ReactNode[] }) {
  return (
    <Card>
      <div className="mb-1 flex items-baseline justify-between gap-2">
        <h2 className="font-semibold">{title}</h2>
        {link && (
          <Link to={link.to} className="text-sm font-medium text-indigo-700 hover:underline">
            {link.label}
          </Link>
        )}
      </div>
      {children.length === 0 ? <p className="py-6 text-center text-sm text-slate-500">{empty}</p> : <ul className="divide-y divide-slate-100">{children}</ul>}
    </Card>
  )
}

function Row({ id, title, meta, value, when, warning }: { id: number; title: string; meta: string; value?: string; when?: ReactNode; warning?: string }) {
  return (
    <li className="flex items-center justify-between gap-3 py-3">
      <div className="min-w-0">
        <Link to={`/admin/auctions/${id}`} className="block truncate text-sm font-medium text-slate-900 hover:text-indigo-700">
          {title}
        </Link>
        <p className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
          {meta}
          {warning && <span className="rounded-full bg-amber-100 px-2 py-0.5 font-medium text-amber-900">{warning}</span>}
        </p>
      </div>
      <div className="shrink-0 text-right text-xs text-slate-500">
        {value && <p className="text-sm font-semibold text-slate-900 tabular-nums">{value}</p>}
        {when}
      </div>
    </li>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="text-xl font-semibold tabular-nums text-slate-900">{value}</dd>
    </div>
  )
}
