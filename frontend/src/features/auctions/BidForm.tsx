import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { useToast } from '../../components/useToast'
import { Alert, Button, Field } from '../../components/ui'
import { newIdempotencyKey } from '../../lib/api'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { retrySameKey } from '../../lib/retry'
import type { Auction } from '../../types/api'
import { auctionKeys, placeBid } from './api'
import { bidSchema, type BidValues } from './bidSchema'
import { quickBidAmounts } from './quickBids'

/** `outbid`: the bidder had bid here and was overtaken; offer the new minimum in one tap. */
export function BidForm({ auction, outbid = false }: { auction: Auction; outbid?: boolean }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const minimum = auction.min_next_bid ?? auction.starting_price
  const schema = useMemo(() => bidSchema(minimum), [minimum])
  const [error, setError] = useState<string | null>(null)
  // One key per bid attempt: reused by automatic retries so the bid is placed at most once.
  const attemptKey = useRef<string | null>(null)

  const form = useForm<BidValues>({ resolver: zodResolver(schema), defaultValues: { amount: minimum } })

  // Keep the suggestion current while the bidder has not typed their own amount.
  useEffect(() => {
    if (!form.formState.dirtyFields.amount) {
      form.setValue('amount', minimum)
    }
  }, [minimum, form])

  const mutation = useMutation({
    mutationFn: (amount: number) => {
      attemptKey.current ??= newIdempotencyKey()
      const key = attemptKey.current
      return retrySameKey(() => placeBid(auction.id, amount, key))
    },
    onSuccess: (result) => {
      queryClient.setQueryData(auctionKeys.detail(auction.id), (current: Auction | undefined) =>
        !current || result.auction.sequence >= current.sequence ? result.auction : current,
      )
      queryClient.invalidateQueries({ queryKey: auctionKeys.bids(auction.id) })
      form.reset({ amount: result.auction.min_next_bid ?? minimum })
      toast.push(
        result.auction.is_leading
          ? { tone: 'success', title: 'Bid placed', body: `You are leading at ${formatRs(result.auction.current_price)}.` }
          : { tone: 'warning', title: 'Bid placed, but outbid', body: `Another bidder's maximum answered. Price is now ${formatRs(result.auction.current_price)}.` },
      )
    },
    onError: (e) => {
      const info = parseApiError(e)
      setError(friendlyMessage(info))
      if (info.code === 'BID_TOO_LOW') {
        queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auction.id) })
      }
    },
    onSettled: () => {
      attemptKey.current = null
    },
  })

  const chosen = useWatch({ control: form.control, name: 'amount' })

  const onSubmit = form.handleSubmit((values) => {
    setError(null)
    mutation.mutate(values.amount)
  })

  return (
    <form onSubmit={onSubmit} className="space-y-3" noValidate aria-label="Place a bid">
      {outbid && (
        <Alert tone="warning">
          <strong>You have been outbid.</strong> The price is now {formatRs(auction.current_price)}. Bid {formatRs(minimum)} or more to lead again.
          {/* A deliberate, labelled action (unlike the quick amounts), so it may place the bid directly. */}
          <button
            type="button"
            disabled={mutation.isPending}
            onClick={() => {
              form.setValue('amount', minimum)
              setError(null)
              mutation.mutate(minimum)
            }}
            className="mt-2 block rounded-md bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-500 disabled:opacity-60"
          >
            Bid {formatRs(minimum)} now
          </button>
        </Alert>
      )}
      {error && <Alert>{error}</Alert>}
      <Field
        label="Your bid (Rs.)"
        type="number"
        inputMode="numeric"
        step={1}
        min={minimum}
        {...form.register('amount', { valueAsNumber: true })}
        error={form.formState.errors.amount?.message}
        hint={`Minimum ${formatRs(minimum)}`}
      />
      {/* Fills the box only: the bid is placed with the button below, never by a single tap. */}
      <div className="flex flex-wrap gap-2" role="group" aria-label="Quick amounts">
        {quickBidAmounts(auction).map((amount) => (
          <button
            key={amount}
            type="button"
            aria-pressed={chosen === amount}
            onClick={() => form.setValue('amount', amount, { shouldDirty: true, shouldValidate: true })}
            className={`rounded-full px-3 py-1 text-xs font-medium tabular-nums ring-1 transition ${chosen === amount ? 'bg-indigo-50 text-indigo-700 ring-indigo-300' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'}`}
          >
            {formatRs(amount)}
          </button>
        ))}
      </div>
      <Button type="submit" busy={mutation.isPending} className="w-full">
        {mutation.isPending ? 'Placing bid…' : 'Place bid'}
      </Button>
    </form>
  )
}
