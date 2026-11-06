import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { useToast } from '../../components/useToast'
import { getEcho } from '../../lib/echo'
import { auctionKeys } from '../auctions/api'
import { inboxKey } from './inboxApi'
import { noticeText, type NoticeData } from './noticeText'

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
      queryClient.invalidateQueries({ queryKey: ['auctions', 'mine'] })
      // The server stored it in the inbox before sending it: the bell picks it up.
      queryClient.invalidateQueries({ queryKey: inboxKey })
    }
    // Offered unless the bidder is already looking at that auction.
    const viewAuction = (auctionId: number) =>
      pathRef.current === `/auctions/${auctionId}` ? undefined : { label: 'View auction', onClick: () => navigate(`/auctions/${auctionId}`) }

    // The same words as the bell; a payment receipt is only kept there (the bidder just paid).
    const show = (type: string) => (n: NoticeData) => {
      const { tone, title, body } = noticeText(type, n)
      toast.push({ tone, title, body, action: viewAuction(n.auction_id) })
      refresh(n.auction_id)
    }

    echo
      .private(`user.${userId}`)
      .listen('.Outbid', show('Outbid'))
      .listen('.AutoBidMaxReached', show('AutoBidMaxReached'))
      .listen('.AuctionWon', show('AuctionWon'))
      .listen('.WatchedAuctionReminder', show('WatchedAuctionReminder'))
      .listen('.PaymentReceived', () => queryClient.invalidateQueries({ queryKey: inboxKey }))

    return () => echo.leave(`user.${userId}`)
  }, [userId, toast, queryClient, navigate])
}
