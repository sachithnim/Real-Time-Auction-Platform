import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { useToast } from '../../components/useToast'
import { getEcho } from '../../lib/echo'
import { formatRs } from '../../lib/money'
import { auctionKeys } from '../auctions/api'
import { reminderToast, type WatchReminderNotice } from './reminders'

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
  const navigate = useNavigate()
  // Read when a notice arrives, without re-subscribing to the channel on every page change.
  const { pathname } = useLocation()
  const pathRef = useRef(pathname)
  useEffect(() => {
    pathRef.current = pathname
  }, [pathname])

  useEffect(() => {
    const echo = getEcho()
    if (!echo || userId === null) {
      return
    }

    const refresh = (auctionId: number) => {
      queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auctionId) })
      queryClient.invalidateQueries({ queryKey: auctionKeys.autoBid(auctionId) })
      // The list cards show "You're leading" / "Outbid" too.
      queryClient.invalidateQueries({ queryKey: ['auctions', 'list'] })
    }
    // Offered unless the bidder is already looking at that auction.
    const viewAuction = (auctionId: number) =>
      pathRef.current === `/auctions/${auctionId}` ? undefined : { label: 'View auction', onClick: () => navigate(`/auctions/${auctionId}`) }

    echo
      .private(`user.${userId}`)
      .listen('.Outbid', (n: Notice) => {
        toast.push({
          tone: 'warning',
          title: `Outbid on ${n.title}`,
          body: `Price is now ${formatRs(n.current_price)}. Bid ${formatRs(n.min_next_bid)} to lead again.`,
          action: viewAuction(n.auction_id),
        })
        refresh(n.auction_id)
      })
      .listen('.AutoBidMaxReached', (n: Notice) => {
        toast.push({
          tone: 'warning',
          title: 'Your maximum was reached',
          body: `${n.title}: your maximum of ${formatRs(n.max_amount)} has been passed.`,
          action: viewAuction(n.auction_id),
        })
        refresh(n.auction_id)
      })
      .listen('.AuctionWon', (n: Notice) => {
        toast.push({ tone: 'success', title: 'You won!', body: `${n.title} for ${formatRs(n.final_price)}.`, action: viewAuction(n.auction_id) })
        refresh(n.auction_id)
      })
      .listen('.WatchedAuctionReminder', (n: WatchReminderNotice) => {
        toast.push({ tone: 'info', ...reminderToast(n), action: viewAuction(n.auction_id) })
        refresh(n.auction_id)
      })

    return () => echo.leave(`user.${userId}`)
  }, [userId, toast, queryClient, navigate])
}
