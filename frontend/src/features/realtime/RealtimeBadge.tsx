import type { RealtimeStatus } from './useRealtimeStatus'

const styles: Record<RealtimeStatus, [string, string]> = {
  live: ['bg-emerald-500', 'Live updates on'],
  reconnecting: ['bg-amber-500 animate-pulse', 'Reconnecting…'],
  offline: ['bg-slate-400', 'Live updates off'],
}

export function RealtimeBadge({ status }: { status: RealtimeStatus }) {
  const [dot, label] = styles[status]
  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-slate-500" role="status">
      <span className={`h-2 w-2 rounded-full ${dot}`} />
      {label}
    </span>
  )
}
