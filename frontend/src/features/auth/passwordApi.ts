import { api, fetchCsrfCookie } from '../../lib/api'

/** Emails a reset link if the account exists. The answer is the same either way. */
export async function requestPasswordReset(email: string) {
  await fetchCsrfCookie()
  await api.post('/api/auth/forgot-password', { email })
}

export interface ResetPasswordInput {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export async function resetPassword(input: ResetPasswordInput) {
  await fetchCsrfCookie()
  await api.post('/api/auth/reset-password', input)
}
