import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { fieldErrors, friendlyMessage, isRetryable, parseApiError } from '../apiError'
import { formatRs } from '../money'

function httpError(status: number, error?: object) {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError('Request failed', 'ERR', config, null, {
    status,
    statusText: '',
    headers: {},
    config,
    data: error ? { error } : {},
  })
}

describe('parseApiError', () => {
  it('reads the common error envelope', () => {
    const info = parseApiError(httpError(422, { code: 'BID_TOO_LOW', message: 'Too low', details: { min_next_bid: 25500 } }))

    expect(info).toEqual({ status: 422, code: 'BID_TOO_LOW', message: 'Too low', details: { min_next_bid: 25500 } })
  })

  it('reports a network failure when there is no response', () => {
    const info = parseApiError(new AxiosError('Network Error', 'ERR_NETWORK'))

    expect(info.code).toBe('NETWORK_ERROR')
    expect(isRetryable(info)).toBe(true)
  })

  it('falls back to the HTTP status when the body has no envelope', () => {
    expect(parseApiError(httpError(502)).code).toBe('HTTP_502')
  })
})

describe('friendlyMessage', () => {
  it('tells the bidder the new minimum when a bid is too low', () => {
    const info = parseApiError(httpError(422, { code: 'BID_TOO_LOW', message: '', details: { min_next_bid: 25500 } }))

    expect(friendlyMessage(info)).toBe('Your bid is too low. The minimum is now Rs. 25,500.')
  })

  it('explains being already in the lead', () => {
    const info = parseApiError(httpError(422, { code: 'INVALID_BID', message: '', details: { reason: 'ALREADY_LEADING' } }))

    expect(friendlyMessage(info)).toBe('You are already the highest bidder.')
  })

  it.each([
    ['AUCTION_ENDED', 'This auction has ended.'],
    ['AUCTION_NOT_STARTED', 'This auction has not started yet.'],
    ['AUCTION_NOT_LIVE', 'This auction is not open for bidding.'],
    ['DUPLICATE_REQUEST', 'Your previous request is still being processed.'],
    ['BID_CONFLICT_RETRY', 'Many people are bidding right now. Please try again.'],
  ])('has a message for %s', (code, message) => {
    expect(friendlyMessage(parseApiError(httpError(409, { code, message: 'raw', details: {} })))).toBe(message)
  })

  it('shows the first field error for validation failures', () => {
    const info = parseApiError(httpError(422, { code: 'VALIDATION_FAILED', message: 'Invalid', details: { errors: { email: ['Email taken.'] } } }))

    expect(fieldErrors(info)).toEqual({ email: 'Email taken.' })
    expect(friendlyMessage(info)).toBe('Email taken.')
  })

  it('hides server internals on 5xx', () => {
    expect(friendlyMessage(parseApiError(httpError(500, { code: 'SERVER_ERROR', message: 'stack trace', details: {} }))))
      .toBe('Something went wrong on our side. Please try again.')
  })
})

describe('formatRs', () => {
  it('formats whole rupees with separators', () => {
    expect(formatRs(25500)).toBe('Rs. 25,500')
    expect(formatRs(null)).toBe('—')
  })
})
