import { parseApiError, type ApiErrorInfo } from './apiError'

/**
 * Retries an action that is safe to repeat because every attempt carries the
 * same Idempotency-Key: the server processes it at most once. Retries only
 * outcomes where the first attempt may not have been processed (network
 * failure, lock conflict, 5xx) or is still running (DUPLICATE_REQUEST).
 */
export async function retrySameKey<T>(
  action: () => Promise<T>,
  { attempts = 3, baseDelayMs = 400, sleep = (ms: number) => new Promise((r) => setTimeout(r, ms)) } = {},
): Promise<T> {
  let lastError: unknown

  for (let attempt = 1; attempt <= attempts; attempt++) {
    try {
      return await action()
    } catch (error) {
      lastError = error
      if (attempt === attempts || !shouldRetry(parseApiError(error))) {
        throw error
      }
      await sleep(baseDelayMs * attempt)
    }
  }

  throw lastError
}

function shouldRetry(info: ApiErrorInfo): boolean {
  return (
    ['NETWORK_ERROR', 'TIMEOUT', 'BID_CONFLICT_RETRY', 'DUPLICATE_REQUEST'].includes(info.code) ||
    (info.status ?? 0) >= 500
  )
}
