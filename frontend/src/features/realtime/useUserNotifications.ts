import { useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useToast } from '../../components/useToast'
import { getEcho } from '../../lib/echo'
import { formatRs } from '../../lib/money'
import { auctionKeys } from '../auctions/api'

interface Notice {
  auction_id: number
  title: string
  current_price?: number
  min_next_bid?: number
  max_amount?: number
  final_price?: number
}

/**
 * Listens on the signed-in bidder's private channel (authorized by the API)
 * for notifications meant only for them.
 */
export function useUserNotifications(userId: number | null) {
  const toast = useToast()
  const queryClient = useQueryClient()

  useEffect(() => {
    const echo = getEcho()
    if (!echo || userId === null) {
      return
    }

    const refresh = (auctionId: number) => {
      queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auctionId) })
      queryClient.invalidateQueries({ queryKey: auctionKeys.autoBid(auctionId) })
    }

    echo
      .private(`user.${userId}`)
      .listen('.Outbid', (n: Notice) => {
        toast.push({ tone: 'warning', title: `Outbid on ${n.title}`, body: `Price is now ${formatRs(n.current_price)}. Bid ${formatRs(n.min_next_bid)} to lead again.` })
        refresh(n.auction_id)
      })
      .listen('.AutoBidMaxReached', (n: Notice) => {
        toast.push({ tone: 'warning', title: 'Your maximum was reached', body: `${n.title}: your maximum of ${formatRs(n.max_amount)} has been passed.` })
        refresh(n.auction_id)
      })
      .listen('.AuctionWon', (n: Notice) => {
        toast.push({ tone: 'success', title: 'You won!', body: `${n.title} for ${formatRs(n.final_price)}.` })
        refresh(n.auction_id)
      })

    return () => echo.leave(`user.${userId}`)
  }, [userId, toast, queryClient])
}
