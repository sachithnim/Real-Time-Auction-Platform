import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect } from 'react'
import { getEcho } from '../../lib/echo'
import type { MonitorAuction, MonitorAuctionGone, MonitorRejection, MonitorSnapshot } from '../../types/api'
import { useRealtimeStatus } from '../realtime/useRealtimeStatus'
import { adminKeys, fetchMonitor } from './api'
import { addMonitorRejection, applyMonitorAuction } from './monitor'

/**
 * The live monitor's data: a REST snapshot kept current by the private
 * admin.monitor channel. Re-fetched after a reconnect (events may have been
 * missed) and every 30 seconds, so "recent bids" counts age out on time.
 */
export function useAdminMonitor() {
  const queryClient = useQueryClient()

  const refetch = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: adminKeys.monitor })
  }, [queryClient])

  const realtime = useRealtimeStatus(refetch)
  const monitor = useQuery({ queryKey: adminKeys.monitor, queryFn: fetchMonitor, refetchInterval: 30_000 })

  useEffect(() => {
    const echo = getEcho()
    if (!echo) {
      return
    }

    const update = (change: (snapshot: MonitorSnapshot) => MonitorSnapshot) =>
      queryClient.setQueryData<MonitorSnapshot>(adminKeys.monitor, (snapshot) => (snapshot ? change(snapshot) : snapshot))

    echo
      .private('admin.monitor')
      .listen('.MonitorAuctionChanged', (event: { auction: MonitorAuction | MonitorAuctionGone }) =>
        update((snapshot) => applyMonitorAuction(snapshot, event.auction)),
      )
      .listen('.MonitorBidRejected', (event: { rejection: MonitorRejection }) =>
        update((snapshot) => addMonitorRejection(snapshot, event.rejection)),
      )

    return () => echo.leave('admin.monitor')
  }, [queryClient])

  return { monitor, realtime }
}
