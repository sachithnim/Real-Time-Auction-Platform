import { isAxiosError } from 'axios'
import { formatRs } from './money'

/** Every API error has this shape: { error: { code, message, details } }. */
export interface ApiErrorInfo {
  status: number | null
  code: string
  message: string
  details: Record<string, unknown>
}

interface ErrorEnvelope {
  error?: { code?: string; message?: string; details?: Record<string, unknown> }
}

export function parseApiError(error: unknown): ApiErrorInfo {
  if (isAxiosError<ErrorEnvelope>(error)) {
    if (!error.response) {
      return {
        status: null,
        code: error.code === 'ECONNABORTED' ? 'TIMEOUT' : 'NETWORK_ERROR',
        message: 'Network error',
        details: {},
      }
    }

    const body = error.response.data?.error
    return {
      status: error.response.status,
      code: body?.code ?? `HTTP_${error.response.status}`,
      message: body?.message ?? error.message,
      details: body?.details ?? {},
    }
  }

  return { status: null, code: 'UNKNOWN', message: String(error), details: {} }
}

/** Field-level validation messages (VALIDATION_FAILED / INVALID_BID), first message per field. */
export function fieldErrors(info: ApiErrorInfo): Record<string, string> {
  const errors = info.details.errors as Record<string, string[]> | undefined
  if (!errors) {
    return {}
  }

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0] ?? '']))
}

/** A message a bidder can act on, for every error case the API defines. */
export function friendlyMessage(info: ApiErrorInfo): string {
  const amount = (key: string) => formatRs(info.details[key] as number | undefined)

  switch (info.code) {
    case 'NETWORK_ERROR':
      return "Can't reach the server. Check your connection and try again."
    case 'TIMEOUT':
      return 'The server took too long to answer. Please try again.'
    case 'BID_TOO_LOW':
      return `Your bid is too low. The minimum is now ${amount('min_next_bid')}.`
    case 'INVALID_BID':
      return info.details.reason === 'ALREADY_LEADING'
        ? 'You are already the highest bidder.'
        : 'Enter a whole number of rupees.'
    case 'MAX_AUTO_BID_REACHED':
      return `Your maximum must be at least ${amount('minimum_max_amount')}.`
    case 'AUCTION_NOT_STARTED':
      return 'This auction has not started yet.'
    case 'AUCTION_ENDED':
      return 'This auction has ended.'
    case 'AUCTION_NOT_LIVE':
      return 'This auction is not open for bidding.'
    case 'AUCTION_NOT_FOUND':
      return 'This auction does not exist.'
    case 'DUPLICATE_REQUEST':
      return 'Your previous request is still being processed.'
    case 'BID_CONFLICT_RETRY':
      return 'Many people are bidding right now. Please try again.'
    case 'TOO_MANY_REQUESTS':
      return 'You are going too fast. Please wait a moment.'
    case 'UNAUTHENTICATED':
      return 'Please log in to continue.'
    case 'FORBIDDEN':
      return 'Your account cannot do this.'
    case 'CSRF_TOKEN_MISMATCH':
      return 'Your session expired. Please try again.'
    case 'INVALID_TRANSITION':
    case 'AUCTION_NOT_EDITABLE':
      return info.message
    case 'VALIDATION_FAILED':
      return Object.values(fieldErrors(info))[0] ?? info.message
    default:
      return info.status !== null && info.status >= 500
        ? 'Something went wrong on our side. Please try again.'
        : info.message
  }
}

/** Errors worth retrying automatically: the request may never have been processed. */
export function isRetryable(info: ApiErrorInfo): boolean {
  return ['NETWORK_ERROR', 'TIMEOUT', 'BID_CONFLICT_RETRY'].includes(info.code) || (info.status ?? 0) >= 500
}
