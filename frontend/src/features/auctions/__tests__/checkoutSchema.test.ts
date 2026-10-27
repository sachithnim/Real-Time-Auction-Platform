import { describe, expect, it } from 'vitest'
import { cardDigits, checkoutSchema, expiryInFuture, passesLuhn } from '../checkoutSchema'

const now = new Date('2026-10-27T12:00:00Z')

describe('card checks', () => {
  it('accepts real test card numbers and rejects a changed digit', () => {
    expect(passesLuhn(cardDigits('4242 4242 4242 4242'))).toBe(true)
    expect(passesLuhn(cardDigits('4000-0000-0000-0002'))).toBe(true)
    expect(passesLuhn(cardDigits('4242 4242 4242 4241'))).toBe(false)
    expect(passesLuhn('4242')).toBe(false)
  })

  it('treats a card as valid until the end of its expiry month', () => {
    expect(expiryInFuture('10/26', now)).toBe(true)
    expect(expiryInFuture('09/26', now)).toBe(false)
    expect(expiryInFuture('13/30', now)).toBe(false)
  })

  it('explains each problem in the form', () => {
    const result = checkoutSchema(() => now).safeParse({ cardholder_name: '', card_number: '4242 4242 4242 4241', expiry: '09/26', cvc: '12' })

    expect(result.success).toBe(false)
    const messages = Object.fromEntries((result.error?.issues ?? []).map((issue) => [issue.path[0], issue.message]))
    expect(messages).toEqual({
      cardholder_name: 'Enter the name on the card.',
      card_number: 'This card number is not valid.',
      expiry: 'This card has expired.',
      cvc: 'The CVC is the 3 digits on the back of the card.',
    })
  })
})
