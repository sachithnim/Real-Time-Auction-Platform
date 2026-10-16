import { useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect } from 'react'
import { getEcho } from '../../lib/echo'
import type { Auction, BidHistoryRow, CursorPage } from '../../types/api'
import { auctionKeys } from '../auctions/api'
import { AUCTION_EVENTS, applyAuctionEvent, type AuctionEventPayload } from './auctionEvents'
import { useRealtimeStatus } from './useRealtimeStatus'

/**
 * Keeps the cached auction and its bid history in sync with auction.{id}.
 * Re-fetches whenever an event was missed (sequence gap) or the connection
 * was lost and came back.
 */
export function useAuctionChannel(auctionId: number) {
  const queryClient = useQueryClient()

  const refetch = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: auctionKeys.detail(auctionId) })
    queryClient.invalidateQueries({ queryKey: auctionKeys.bids(auctionId) })
  }, [auctionId, queryClient])

  const status = useRealtimeStatus(refetch)

  useEffect(() => {
    const echo = getEcho()
    if (!echo) {
      return
    }

    const channel = echo.channel(`auction.${auctionId}`)

    for (const name of AUCTION_EVENTS) {
      channel.listen(`.${name}`, (payload: AuctionEventPayload) => {
        const current = queryClient.getQueryData<Auction>(auctionKeys.detail(auctionId))
        if (!current) {
          return // The snapshot being fetched will already include this change.
        }

        const result = applyAuctionEvent(current, name, payload)

        if (result.kind === 'gap') {
          refetch()
          return
        }

        if (result.kind === 'applied') {
          queryClient.setQueryData(auctionKeys.detail(auctionId), result.auction)
          if (result.newBid) {
            const bid = result.newBid
            queryClient.setQueryData<CursorPage<BidHistoryRow>>(auctionKeys.bids(auctionId), (history) =>
              history && !history.data.some((row) => row.id === bid.id) ? { ...history, data: [bid, ...history.data] } : history,
            )
          }
        }
      })
    }

    return () => echo.leaveChannel(`auction.${auctionId}`)
  }, [auctionId, queryClient, refetch])

  return status
}
