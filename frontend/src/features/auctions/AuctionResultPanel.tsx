import { Alert } from '../../components/ui'
import { formatRs } from '../../lib/money'
import type { Auction } from '../../types/api'

export function AuctionResultPanel({ auction }: { auction: Auction }) {
  if (auction.status === 'CANCELLED') {
    return <Alert>This auction was cancelled.</Alert>
  }

  if (auction.status !== 'COMPLETED' || !auction.result) {
    return null
  }

  if (auction.result === 'SOLD') {
    return auction.is_winner ? (
      <Alert tone="success">
        <strong>You won!</strong> Final price {formatRs(auction.final_price)}.
      </Alert>
    ) : (
      <Alert tone="info">
        Sold to {auction.winner} for <strong>{formatRs(auction.final_price)}</strong>.
      </Alert>
    )
  }

  if (auction.result === 'RESERVE_NOT_MET') {
    return <Alert tone="info">The auction ended without a sale: the reserve price was not reached.</Alert>
  }

  return <Alert tone="info">The auction ended with no bids.</Alert>
}
