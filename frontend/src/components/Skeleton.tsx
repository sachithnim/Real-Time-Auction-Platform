import type { ReactNode } from 'react'
import { Card } from './ui'

/** A grey placeholder block shaped like the content that is loading. */
export function Skeleton({ className = '' }: { className?: string }) {
  return <div className={`rounded bg-slate-200 motion-safe:animate-pulse ${className}`} aria-hidden />
}

/** Wraps placeholders so screen readers hear one "Loading" instead of nothing. */
function Loading({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div role="status" aria-label={label}>
      <span className="sr-only">{label}</span>
      {children}
    </div>
  )
}

/** The auction list while it loads: cards in the same grid as the real ones. */
export function AuctionCardsSkeleton({ count = 6 }: { count?: number }) {
  return (
    <Loading label="Loading auctions">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: count }, (_, i) => (
          <Card key={i} className="space-y-3">
            <div className="flex justify-between gap-4">
              <Skeleton className="h-5 w-2/3" />
              <Skeleton className="h-5 w-14 rounded-full" />
            </div>
            <Skeleton className="h-3 w-1/4" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
            <div className="flex items-end justify-between pt-1">
              <div className="space-y-1.5">
                <Skeleton className="h-3 w-16" />
                <Skeleton className="h-6 w-24" />
              </div>
              <Skeleton className="h-7 w-28" />
            </div>
          </Card>
        ))}
      </div>
    </Loading>
  )
}

/** The live auction screen while it loads: title, description, history and the price panel. */
export function AuctionDetailSkeleton() {
  return (
    <Loading label="Loading auction">
      <div className="space-y-6">
        <div className="space-y-2">
          <Skeleton className="h-8 w-72 max-w-full" />
          <Skeleton className="h-3 w-24" />
        </div>
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            <Card className="space-y-2">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-2/3" />
            </Card>
            <Card className="space-y-3">
              <Skeleton className="h-5 w-28" />
              <RowsSkeleton rows={4} />
            </Card>
          </div>
          <div className="order-first lg:order-none">
            <Card className="space-y-4">
              <Skeleton className="h-3 w-24" />
              <Skeleton className="h-9 w-40" />
              <Skeleton className="h-7 w-32" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </Card>
          </div>
        </div>
      </div>
    </Loading>
  )
}

/** Plain rows, e.g. a bid history or a list of flags. */
export function RowsSkeleton({ rows = 5, label }: { rows?: number; label?: string }) {
  const lines = (
    <div className="divide-y divide-slate-100">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="flex items-center justify-between gap-4 py-2.5">
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="h-4 w-20" />
        </div>
      ))}
    </div>
  )
  return label ? <Loading label={label}>{lines}</Loading> : lines
}

/** A card of rows for admin tables and lists. */
export function TableSkeleton({ rows = 5, label = 'Loading' }: { rows?: number; label?: string }) {
  return (
    <Loading label={label}>
      <Card className="space-y-3">
        <Skeleton className="h-4 w-full" />
        <RowsSkeleton rows={rows} />
      </Card>
    </Loading>
  )
}

/** A row of figure tiles above a table, as on the monitor and statistics pages. */
export function DashboardSkeleton({ tiles = 3, label }: { tiles?: number; label: string }) {
  return (
    <Loading label={label}>
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <div className={`grid grid-cols-1 gap-4 ${tiles === 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3'}`}>
          {Array.from({ length: tiles }, (_, i) => (
            <Card key={i} className="space-y-2">
              <Skeleton className="h-3 w-24" />
              <Skeleton className="h-7 w-16" />
            </Card>
          ))}
        </div>
        <Card className="space-y-3">
          <RowsSkeleton rows={4} />
        </Card>
      </div>
    </Loading>
  )
}
