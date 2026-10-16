import { QueryClient } from '@tanstack/react-query'
import { isRetryable, parseApiError } from './apiError'

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Retry only failures that may succeed later (network, 5xx); a 404 or 403 will not.
        retry: (failureCount, error) => failureCount < 3 && isRetryable(parseApiError(error)),
        retryDelay: (attempt) => Math.min(1_000 * 2 ** attempt, 8_000),
        refetchOnWindowFocus: true,
      },
      mutations: { retry: false },
    },
  })
}
