import { api } from '../../lib/api'

export type EmailType = 'OUTBID' | 'MAX_REACHED' | 'WON' | 'REMINDERS' | 'RECEIPTS'
export type EmailPreferences = Record<EmailType, boolean>

/** What each email is, in the order the settings page lists them. */
export const emailTypes: { type: EmailType; label: string; description: string }[] = [
  { type: 'OUTBID', label: 'Outbid', description: 'Someone takes the lead from you. At most one email per auction every 10 minutes.' },
  { type: 'MAX_REACHED', label: 'Maximum passed', description: 'The price goes above your automatic maximum.' },
  { type: 'WON', label: 'You won', description: 'An auction closes with you as the winner, with a link to pay.' },
  { type: 'REMINDERS', label: 'Watchlist reminders', description: 'An auction you watch is about to start or end.' },
  { type: 'RECEIPTS', label: 'Payment receipts', description: 'A receipt each time a payment goes through.' },
]

export const emailPreferencesKey = ['account', 'email-preferences'] as const

export async function fetchEmailPreferences() {
  const { data } = await api.get<{ data: EmailPreferences }>('/api/me/email-preferences')
  return data.data
}

/** Send only the types being changed; the answer is the full, saved set. */
export async function updateEmailPreferences(changes: Partial<EmailPreferences>) {
  const { data } = await api.put<{ data: EmailPreferences }>('/api/me/email-preferences', { preferences: changes })
  return data.data
}
