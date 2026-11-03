import { api } from '../../lib/api'
import { formatRs } from '../../lib/money'
import type { MyBidAuction, MyBidCounts, Paginated } from '../../types/api'

/** A tab on the page: everything, still live, won, or not won (lost or cancelled). */
export type MyBidsFilter = 'all' | 'active' | 'won' | 'lost'

export const myBidsFilters: { value: MyBidsFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'won', label: 'Won' },
  { value: 'lost', label: 'Didn’t win' },
]

// Under 'auctions', so anything that refreshes every auction refreshes this too.
export const myBidsKeys = {
  all: ['auctions', 'mine'] as const,
  list: (filter: MyBidsFilter, page: number) => ['auctions', 'mine', filter, page] as const,
}

export type MyBidsPage = Paginated<MyBidAuction> & { counts: MyBidCounts }

export async function fetchMyBids(filter: MyBidsFilter, page: number) {
  const { data } = await api.get<MyBidsPage>('/api/me/bids', {
    params: { filter: filter === 'all' ? undefined : filter, page, per_page: 10 },
  })
  return data
}

export function readFilter(value: string | null): MyBidsFilter {
  return myBidsFilters.some((f) => f.value === value) ? (value as MyBidsFilter) : 'all'
}

export interface MyBidSummary {
  badge: { text: string; tone: 'good' | 'warning' | 'neutral' }
  /** One line under the title that explains the badge, if it needs explaining. */
  note: string | null
  /** The obvious next step; primary when it needs the bidder (bid again, pay). */
  action: { label: string; primary: boolean }
}

/** What a row says and offers, from how the bidder stands in that auction. */
export function myBidSummary(auction: MyBidAuction): MyBidSummary {
  const { state, max_amount, max_active, payment_status } = auction.my_bid

  switch (state) {
    case 'LEADING':
      return {
        badge: { text: 'You’re leading', tone: 'good' },
        note: max_amount !== null && max_active ? `Your maximum of ${formatRs(max_amount)} bids for you if someone outbids you.` : null,
        action: { label: 'View', primary: false },
      }
    case 'OUTBID':
      return {
        badge: { text: 'Outbid', tone: 'warning' },
        note: max_amount !== null && !max_active ? `Your maximum of ${formatRs(max_amount)} was passed.` : null,
        action: { label: 'Bid again', primary: true },
      }
    case 'WON':
      if (payment_status === 'PAID') {
        return { badge: { text: 'Won · Paid', tone: 'good' }, note: null, action: { label: 'View receipt', primary: false } }
      }
      return {
        badge: { text: 'Won', tone: 'good' },
        note: payment_status === 'FAILED' ? 'Your last payment was declined. Try another card.' : 'Waiting for your payment.',
        action: { label: 'Pay now', primary: true },
      }
    case 'LOST':
      if (auction.result === 'RESERVE_NOT_MET' && auction.is_leading) {
        return {
          badge: { text: 'Reserve not met', tone: 'neutral' },
          note: 'Your bid was the highest, but below the seller’s reserve, so the item did not sell.',
          action: { label: 'View', primary: false },
        }
      }
      return {
        badge: { text: 'Not won', tone: 'neutral' },
        note: auction.result === 'SOLD' && auction.winner ? `Won by ${auction.winner}.` : null,
        action: { label: 'View', primary: false },
      }
    case 'CANCELLED':
      return { badge: { text: 'Cancelled', tone: 'neutral' }, note: 'The auction was cancelled, so no one won it.', action: { label: 'View', primary: false } }
  }
}
