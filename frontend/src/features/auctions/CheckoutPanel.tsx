import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Card, Field, PageLoader } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { newIdempotencyKey } from '../../lib/api'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { retrySameKey } from '../../lib/retry'
import type { Auction, Payment } from '../../types/api'
import { auctionKeys, fetchMyPayment, payForAuction } from './api'
import { checkoutSchema, type CheckoutValues } from './checkoutSchema'

const declineReasons: Record<string, string> = {
  CARD_DECLINED: 'Your card was declined.',
  INSUFFICIENT_FUNDS: 'Your card has insufficient funds.',
}

/**
 * The winner's simulated checkout (bonus challenge: payment simulation).
 * Nothing is charged; test card numbers decide the outcome.
 */
export function CheckoutPanel({ auction }: { auction: Auction }) {
  const payment = useQuery({ queryKey: auctionKeys.payment(auction.id), queryFn: () => fetchMyPayment(auction.id) })

  if (payment.isPending) {
    return <PageLoader />
  }

  if (payment.isError) {
    return <Alert>{friendlyMessage(parseApiError(payment.error))}</Alert>
  }

  return payment.data.status === 'PAID' ? <Receipt payment={payment.data} /> : <CheckoutForm auction={auction} payment={payment.data} />
}

function Receipt({ payment }: { payment: Payment }) {
  return (
    <Card className="space-y-1">
      <h2 className="font-semibold text-emerald-700">Paid ✓</h2>
      <p className="text-sm">
        {formatRs(payment.amount)} paid by {payment.card_brand} •••• {payment.card_last4}
        {payment.paid_at && <> on {new Date(payment.paid_at).toLocaleString()}</>}.
      </p>
      <p className="text-xs text-slate-500">Receipt {payment.reference}</p>
    </Card>
  )
}

function CheckoutForm({ auction, payment }: { auction: Auction; payment: Payment }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [error, setError] = useState<string | null>(null)
  // One key per payment attempt, reused by automatic retries so a card is charged at most once.
  const attemptKey = useRef<string | null>(null)
  const schema = useMemo(() => checkoutSchema(), [])
  const form = useForm<CheckoutValues>({ resolver: zodResolver(schema) })
  const errors = form.formState.errors

  const pay = useMutation({
    mutationFn: (card: CheckoutValues) => {
      attemptKey.current ??= newIdempotencyKey()
      const key = attemptKey.current
      return retrySameKey(() => payForAuction(auction.id, card, key))
    },
    onSuccess: (result) => {
      queryClient.setQueryData(auctionKeys.payment(auction.id), result)
      if (result.status === 'PAID') {
        toast.push({ tone: 'success', title: 'Payment received', body: `${auction.title}: receipt ${result.reference}.` })
      } else {
        form.resetField('cvc')
      }
    },
    onError: (e) => {
      const info = parseApiError(e)
      setError(friendlyMessage(info))
      if (info.code === 'PAYMENT_ALREADY_COMPLETED') {
        queryClient.invalidateQueries({ queryKey: auctionKeys.payment(auction.id) })
      }
    },
    onSettled: () => {
      attemptKey.current = null
    },
  })

  const onSubmit = form.handleSubmit((values) => {
    setError(null)
    pay.mutate(values)
  })

  return (
    <Card className="space-y-4">
      <div>
        <h2 className="font-semibold">Pay for your win</h2>
        <p className="text-sm text-slate-600">
          Amount due: <strong>{formatRs(payment.amount)}</strong>. This is a simulated checkout: no money is taken.
        </p>
      </div>

      {payment.status === 'FAILED' && (
        <Alert>{declineReasons[payment.failure_reason ?? ''] ?? 'Your last payment did not go through.'} Try another card.</Alert>
      )}
      {error && <Alert>{error}</Alert>}

      <form onSubmit={onSubmit} className="space-y-3" noValidate aria-label="Card payment">
        <Field label="Name on card" autoComplete="cc-name" {...form.register('cardholder_name')} error={errors.cardholder_name?.message} />
        <Field
          label="Card number"
          inputMode="numeric"
          autoComplete="cc-number"
          placeholder="4242 4242 4242 4242"
          {...form.register('card_number')}
          error={errors.card_number?.message}
        />
        <div className="grid grid-cols-2 gap-3">
          <Field label="Expiry (MM/YY)" inputMode="numeric" autoComplete="cc-exp" placeholder="12/29" {...form.register('expiry')} error={errors.expiry?.message} />
          <Field label="CVC" inputMode="numeric" autoComplete="cc-csc" placeholder="123" {...form.register('cvc')} error={errors.cvc?.message} />
        </div>
        <Button type="submit" busy={pay.isPending} className="w-full">
          {pay.isPending ? 'Paying…' : `Pay ${formatRs(payment.amount)}`}
        </Button>
        <p className="text-xs text-slate-500">
          Test cards: <code>4242 4242 4242 4242</code> pays; <code>4000 0000 0000 0002</code> is declined. Any future expiry and any 3-digit CVC.
        </p>
      </form>
    </Card>
  )
}
