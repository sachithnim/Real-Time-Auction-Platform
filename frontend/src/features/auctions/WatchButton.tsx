import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useToast } from '../../components/useToast'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import type { Auction, Paginated } from '../../types/api'
import { useAuth } from '../auth/useAuth'
import { auctionKeys, unwatchAuction, watchAuction } from './api'

/**
 * Adds or removes an auction from the signed-in bidder's watchlist (bonus
 * challenge: watchlists). Shown to bidders only; guests and admins see nothing.
 */
export function WatchButton({ auction }: { auction: Auction }) {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const toast = useToast()

  const mutation = useMutation({
    mutationFn: (watch: boolean) => (watch ? watchAuction(auction.id) : unwatchAuction(auction.id)),
    onSuccess: (_, watch) => {
      const mark = (item: Auction) => (item.id === auction.id ? { ...item, is_watching: watch } : item)
      queryClient.setQueryData<Auction>(auctionKeys.detail(auction.id), (current) => (current ? mark(current) : current))
      queryClient.setQueriesData<Paginated<Auction>>({ queryKey: ['auctions', 'list'] }, (current) =>
        current ? { ...current, data: current.data.map(mark) } : current,
      )
      // The "Watching" tab gains or loses this auction: let the server re-list it.
      queryClient.invalidateQueries({ queryKey: ['auctions', 'list', 'WATCHING'] })
    },
    onError: (error) => {
      toast.push({ tone: 'error', title: 'Your watchlist was not updated', body: friendlyMessage(parseApiError(error)) })
    },
  })

  if (user?.role !== 'BIDDER') {
    return null
  }

  const watching = auction.is_watching

  return (
    <button
      type="button"
      aria-pressed={watching}
      disabled={mutation.isPending}
      onClick={(event) => {
        // The button sits inside a card that links to the auction.
        event.preventDefault()
        event.stopPropagation()
        mutation.mutate(!watching)
      }}
      className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 transition disabled:opacity-60 ${
        watching ? 'bg-amber-50 text-amber-800 ring-amber-300' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
      }`}
    >
      <span aria-hidden>{watching ? '★' : '☆'}</span>
      {watching ? 'Watching' : 'Watch'}
    </button>
  )
}
