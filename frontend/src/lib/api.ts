import axios, { type InternalAxiosRequestConfig } from 'axios'
import { parseApiError } from './apiError'
import { recordServerTime } from './serverClock'

declare module 'axios' {
  interface InternalAxiosRequestConfig {
    sentAt?: number
    csrfRetried?: boolean
  }
}

/**
 * Talks to the Laravel API with Sanctum's cookie-based SPA auth: the session
 * cookie travels with every request and axios copies the XSRF-TOKEN cookie
 * into the X-XSRF-TOKEN header.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  withXSRFToken: true,
  timeout: 15_000,
  headers: { Accept: 'application/json' },
})

export function fetchCsrfCookie() {
  return api.get('/sanctum/csrf-cookie')
}

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  config.sentAt = Date.now()
  return config
})

api.interceptors.response.use(
  (response) => {
    const serverTime = response.data?.meta?.server_time
    if (typeof serverTime === 'string' && response.config.sentAt) {
      recordServerTime(serverTime, response.config.sentAt, Date.now())
    }
    return response
  },
  async (error) => {
    const config = error.config as InternalAxiosRequestConfig | undefined
    // An expired XSRF token: fetch a fresh one and replay the request once.
    if (config && !config.csrfRetried && parseApiError(error).code === 'CSRF_TOKEN_MISMATCH') {
      config.csrfRetried = true
      await fetchCsrfCookie()
      return api.request(config)
    }
    return Promise.reject(error)
  },
)

/** One key per user action; reused when that same action is retried. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID()
}
