import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Alert, Button, Field } from '../../components/ui'
import { useToast } from '../../components/useToast'
import { newIdempotencyKey } from '../../lib/api'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { formatRs } from '../../lib/money'
import { retrySameKey } from '../../lib/retry'
import type { Auction } from '../../types/api'
import { auctionKeys, fetchMyAutoBid, setAutoBid } from './api'
import { MAX_AMOUNT } from './bidSchema'

/**
 * The bidder's private maximum: the system bids for them only as much as
 * needed, up to this amount. Only the owner ever sees it.
 */
export function AutoBidForm({ auction }: { auction: Auction }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [error, setError] = useState<string | null>(null)
  const attemptKey = useRef<string | null>(null)

  const myAutoBid = useQuery({ queryKey: auctionKeys.autoBid(auction.id), queryFn: () => fetchMyAutoBid(auction.id) })
  const active = myAutoBid.data?.is_active ? myAutoBid.data : null

  // A maximum can only be raised and must cover the next valid bid.
  const floor = Math.max(auction.min_next_bid ?? auction.starting_price, active?.max_amount ?? 0)
  const schema = useMemo(
    () =>
      z.object({
        max_amount: z
          .number({ error: 'Enter your maximum in rupees.' })
          .int('Enter a whole number of rupees.')
          .min(floor, `Your maximum must be at least ${formatRs(floor)}.`)
          .max(MAX_AMOUNT, 'That amount is too large.'),
      }),
    [floor],
  )
  const form = useForm<{ max_amount: number }>({ resolver: zodResolver(schema) })

  const mutation = useMutation({
    mutationFn: (maxAmount: number) => {
      attemptKey.current ??= newIdempotencyKey()
      const key = attemptKey.current
      return retrySameKey(() => setAutoBid(auction.id, maxAmount, key))
    },
    onSuccess: (result) => {
      queryClient.setQueryData(auctionKeys.autoBid(auction.id), result.auto_bid)
      queryClient.setQueryData(auctionKeys.detail(auction.id), (current: Auction | undefined) =>
        !current || result.auction.sequence >= current.sequence ? result.auction : current,
      )
      queryClient.invalidateQueries({ queryKey: auctionKeys.bids(auction.id) })
      form.reset({ max_amount: undefined })
      toast.push(
        result.auction.is_leading
          ? { tone: 'success', title: 'Auto-bid set', body: `You lead at ${formatRs(result.auction.current_price)}. We'll bid up to ${formatRs(result.auto_bid.max_amount)} for you.` }
          : { tone: 'warning', title: 'Outbid already', body: `Another bidder's maximum is higher. Price is now ${formatRs(result.auction.current_price)}.` },
      )
    },
    onError: (e) => setError(friendlyMessage(parseApiError(e))),
    onSettled: () => {
      attemptKey.current = null
    },
  })

  const onSubmit = form.handleSubmit((values) => {
    setError(null)
    mutation.mutate(values.max_amount)
  })

  return (
    <form onSubmit={onSubmit} className="space-y-3" noValidate aria-label="Automatic bidding">
      <div>
        <h3 className="text-sm font-semibold">Automatic bidding</h3>
        <p className="text-xs text-slate-500">
          Set the most you would pay. We bid only as much as needed to keep you in the lead. Nobody else sees your maximum.
        </p>
      </div>
      {active && (
        <p className="rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-800">
          Your maximum: <strong>{formatRs(active.max_amount)}</strong>
        </p>
      )}
      {myAutoBid.data && !myAutoBid.data.is_active && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
          Your maximum of {formatRs(myAutoBid.data.max_amount)} was reached. Set a higher one to keep bidding.
        </p>
      )}
      {error && <Alert>{error}</Alert>}
      <Field
        label={active ? 'Raise maximum to (Rs.)' : 'Maximum (Rs.)'}
        type="number"
        inputMode="numeric"
        step={1}
        min={floor}
        placeholder={String(floor)}
        {...form.register('max_amount', { valueAsNumber: true })}
        error={form.formState.errors.max_amount?.message}
        hint={`At least ${formatRs(floor)}`}
      />
      <Button type="submit" variant="secondary" busy={mutation.isPending} className="w-full">
        {active ? 'Raise maximum' : 'Start auto-bidding'}
      </Button>
    </form>
  )
}
