import { useEffect, useRef, useState } from 'react'
import { getEcho, onConnectionChange } from '../../lib/echo'

export type RealtimeStatus = 'live' | 'reconnecting' | 'offline'

/**
 * Tracks the WebSocket connection. `onReconnect` runs when the connection comes
 * back after a drop: events sent meanwhile were missed, so callers re-fetch.
 */
export function useRealtimeStatus(onReconnect?: () => void): RealtimeStatus {
  const [status, setStatus] = useState<RealtimeStatus>(() => {
    const echo = getEcho()
    if (!echo) {
      return 'offline'
    }
    return echo.connector.pusher.connection.state === 'connected' ? 'live' : 'reconnecting'
  })
  const callback = useRef(onReconnect)

  useEffect(() => {
    callback.current = onReconnect
  })

  useEffect(() => {
    if (!getEcho()) {
      return
    }

    let wasConnected = getEcho()?.connector.pusher.connection.state === 'connected'

    return onConnectionChange((current) => {
      if (current === 'connected') {
        if (wasConnected === false) {
          callback.current?.()
        }
        wasConnected = true
        setStatus('live')
      } else {
        if (wasConnected) {
          wasConnected = false
        }
        setStatus('reconnecting')
      }
    })
  }, [])

  return status
}
