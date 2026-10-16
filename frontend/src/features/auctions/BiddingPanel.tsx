import { Link } from 'react-router'
import type { Auction } from '../../types/api'
import { useAuth } from '../auth/useAuth'

/** Bid and auto-bid forms; filled in by the next steps. */
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

  return null
}
