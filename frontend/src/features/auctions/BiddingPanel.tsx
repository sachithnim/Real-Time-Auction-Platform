import { Link } from 'react-router'
import type { Auction } from '../../types/api'
import { useAuth } from '../auth/useAuth'
import { AutoBidForm } from './AutoBidForm'
import { BidForm } from './BidForm'

/** Bid and auto-bid forms for bidders on a live auction. */
export function BiddingPanel({ auction }: { auction: Auction }) {
  const { user } = useAuth()

  if (auction.status !== 'LIVE') {
    return null
  }

  if (!user) {
    return (
      <p className="text-sm text-slate-600">
        <Link to="/login" className="font-semibold text-indigo-600 hover:underline">Log in</Link> to bid.
      </p>
    )
  }

  if (user.role === 'ADMIN') {
    return <p className="text-sm text-slate-500">Admins cannot bid.</p>
  }

  return (
    <div className="space-y-5 border-t border-slate-100 pt-4">
      {auction.is_leading ? (
        <p className="text-sm text-emerald-700">You are the highest bidder. Raise your maximum below to stay ahead automatically.</p>
      ) : (
        <BidForm auction={auction} />
      )}
      <div className="border-t border-slate-100 pt-4">
        <AutoBidForm auction={auction} />
      </div>
    </div>
  )
}
