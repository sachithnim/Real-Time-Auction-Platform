import { describe, expect, it } from 'vitest'
import type { AdminPayment } from '../../../types/api'
import { paymentDetails } from '../paymentFormat'

const payment = (overrides: Partial<AdminPayment>): AdminPayment => ({
  id: 1,
  auction: { id: 7, title: 'Brass Telescope' },
  bidder: { id: 2, name: 'Asha Perera', email: 'asha@example.com' },
  amount: 20_000,
  status: 'PENDING',
  attempts: 0,
  card_brand: null,
  card_last4: null,
  reference: null,
  failure_reason: null,
  paid_at: null,
  created_at: '2026-11-01T10:00:00Z',
  ...overrides,
})

describe('paymentDetails', () => {
  it('a paid payment shows its receipt and card', () => {
    expect(paymentDetails(payment({ status: 'PAID', reference: 'LB-7', card_brand: 'Visa', card_last4: '4242', paid_at: '2026-11-02T10:00:00Z' })))
      .toMatch(/^LB-7 · Visa •••• 4242 · /)
  })

  it('a declined payment shows the reason and how many tries', () => {
    expect(paymentDetails(payment({ status: 'FAILED', failure_reason: 'Card declined', card_brand: 'Visa', card_last4: '0002', attempts: 2 })))
      .toBe('Card declined (Visa •••• 0002) · 2 attempts')
    expect(paymentDetails(payment({ status: 'FAILED', attempts: 1 }))).toBe('Declined · 1 attempt')
  })

  it('a waiting payment says since when', () => {
    expect(paymentDetails(payment({}))).toMatch(/^Waiting since /)
  })
})
