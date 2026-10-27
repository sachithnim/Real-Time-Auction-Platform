import { z } from 'zod'

/** Digits only: "4242 4242-4242 4242" → "4242424242424242". */
export function cardDigits(value: string): string {
  return value.replace(/[\s-]/g, '')
}

/** The Luhn checksum every real card number satisfies (the server checks it too). */
export function passesLuhn(digits: string): boolean {
  if (!/^\d{12,19}$/.test(digits)) {
    return false
  }
  let sum = 0
  ;[...digits].reverse().forEach((char, i) => {
    let value = Number(char)
    if (i % 2 === 1) {
      value *= 2
      if (value > 9) value -= 9
    }
    sum += value
  })
  return sum % 10 === 0
}

/** True when an MM/YY expiry is this month or later, measured at `now`. */
export function expiryInFuture(expiry: string, now: Date): boolean {
  const match = /^(0[1-9]|1[0-2])\/(\d{2})$/.exec(expiry)
  if (!match) {
    return false
  }
  const endOfMonth = new Date(Date.UTC(2000 + Number(match[2]), Number(match[1]), 1))
  return endOfMonth.getTime() > now.getTime()
}

/** Quick, friendly checks before sending; the server checks everything again. */
export function checkoutSchema(now: () => Date = () => new Date()) {
  return z.object({
    cardholder_name: z.string().trim().min(1, 'Enter the name on the card.').max(100),
    card_number: z.string().refine((v) => passesLuhn(cardDigits(v)), 'This card number is not valid.'),
    expiry: z
      .string()
      .regex(/^(0[1-9]|1[0-2])\/\d{2}$/, 'Enter the expiry as MM/YY.')
      .refine((v) => expiryInFuture(v, now()), 'This card has expired.'),
    cvc: z.string().regex(/^\d{3}$/, 'The CVC is the 3 digits on the back of the card.'),
  })
}

export type CheckoutValues = z.infer<ReturnType<typeof checkoutSchema>>
