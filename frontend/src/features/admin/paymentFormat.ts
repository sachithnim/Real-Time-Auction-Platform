import type { AdminPayment } from '../../types/api'

/** One line on what happened: the receipt when paid, the reason when declined. */
export function paymentDetails(payment: AdminPayment): string {
  const card = payment.card_brand && payment.card_last4 ? `${payment.card_brand} •••• ${payment.card_last4}` : null
  switch (payment.status) {
    case 'PAID':
      return [payment.reference, card, payment.paid_at && new Date(payment.paid_at).toLocaleString()].filter(Boolean).join(' · ')
    case 'FAILED':
      return `${payment.failure_reason ?? 'Declined'}${card ? ` (${card})` : ''} · ${payment.attempts} ${payment.attempts === 1 ? 'attempt' : 'attempts'}`
    case 'PENDING':
      return `Waiting since ${new Date(payment.created_at).toLocaleDateString()}`
  }
}
