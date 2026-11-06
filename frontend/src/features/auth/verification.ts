import { api } from '../../lib/api'

/** Sends the "Confirm your email" link again. */
export async function resendVerification() {
  await api.post('/api/me/email/verification-notification')
}

/** Where the emailed link lands, by the outcome the API redirected with. */
export type VerifiedStatus = 'verified' | 'already' | 'invalid'

export function readVerifiedStatus(value: string | null): VerifiedStatus {
  return value === 'verified' || value === 'already' ? value : 'invalid'
}
