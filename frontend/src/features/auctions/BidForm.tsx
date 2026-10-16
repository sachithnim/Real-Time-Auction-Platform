import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useToast } from '../../components/useToast'
import { Alert, Button, Field } from '../../components/ui'
import { newIdempotencyKey } from '../../lib/api'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { retrySameKey } from '../../lib/retry'
import type { Auction } from '../../types/api'
import { auctionKeys, placeBid } from './api'
import { bidSchema, type BidValues } from './bidSchema'

export function BidForm({ auction }: { auction: Auction }) {
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

  const onSubmit = form.handleSubmit((values) => {
    setError(null)
    mutation.mutate(values.amount)
  })

  return (
    <form onSubmit={onSubmit} className="space-y-3" noValidate aria-label="Place a bid">
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
      <Button type="submit" busy={mutation.isPending} className="w-full">
        {mutation.isPending ? 'Placing bid…' : 'Place bid'}
      </Button>
    </form>
  )
}
