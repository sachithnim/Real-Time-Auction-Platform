import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from './api'

/** What /api/broadcasting/auth returns for a private channel (Pusher protocol). */
interface ChannelAuthData {
  auth: string
  channel_data?: string
}

let echo: Echo<'reverb'> | null = null

/**
 * One shared WebSocket connection to Laravel Reverb. Returns null when no key
 * is configured, so the app still works (without live updates).
 */
export function getEcho(): Echo<'reverb'> | null {
  if (echo) {
    return echo
  }

  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) {
    return null
  }

  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080)
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    // Private channels are authorized by our API using the session cookie.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: ChannelAuthData | null) => void) => {
        api
          .post('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((response) => callback(null, response.data as ChannelAuthData))
          .catch((error: Error) => callback(error, null))
      },
    }),
  })

  return echo
}

type ConnectionState = 'connecting' | 'connected' | 'unavailable' | 'disconnected' | 'failed' | 'initialized'

/** Subscribes to connection state changes; returns an unsubscribe function. */
export function onConnectionChange(listener: (current: ConnectionState, previous: ConnectionState) => void): () => void {
  const connection = getEcho()?.connector.pusher.connection
  if (!connection) {
    return () => {}
  }

  const handler = ({ current, previous }: { current: ConnectionState; previous: ConnectionState }) => listener(current, previous)
  connection.bind('state_change', handler)

  return () => connection.unbind('state_change', handler)
}
