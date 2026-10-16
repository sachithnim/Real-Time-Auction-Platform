import { z } from 'zod'
import { formatRs } from '../../lib/money'

export const MAX_AMOUNT = 1_000_000_000_000

/**
 * Client-side check for a quick, friendly answer. The server re-validates
 * every bid under the auction lock; this never decides whether a bid is valid.
 */
export function bidSchema(minimum: number) {
  return z.object({
    amount: z
      .number({ error: 'Enter an amount in rupees.' })
      .int('Enter a whole number of rupees.')
      .min(minimum, `Your bid must be at least ${formatRs(minimum)}.`)
      .max(MAX_AMOUNT, 'That amount is too large.'),
  })
}

export type BidValues = z.infer<ReturnType<typeof bidSchema>>
