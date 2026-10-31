import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { BidHistoryRow } from '../../../types/api'
import { auctionKeys, fetchBidHistory } from '../api'
import { BidHistory } from '../BidHistory'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchBidHistory: vi.fn(),
}))

const bid = (id: number, amount: number): BidHistoryRow =>
  ({ id, amount, type: 'MANUAL', bidder: `Bidder ${id}`, is_you: false, created_at: '2026-10-28T10:00:00Z' }) as BidHistoryRow

const page = (bids: BidHistoryRow[]) => ({ data: bids }) as Awaited<ReturnType<typeof fetchBidHistory>>

beforeEach(() => {
  vi.mocked(fetchBidHistory).mockReset()
})

describe('<BidHistory>', () => {
  it('marks only bids that arrive after the page opened as new', async () => {
    vi.mocked(fetchBidHistory).mockResolvedValue(page([bid(2, 25_000), bid(1, 24_500)]))
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <BidHistory auctionId={3} status="LIVE" />
      </QueryClientProvider>,
    )

    const list = await screen.findByRole('list', { name: 'Bid history' })
    expect(within(list).getAllByRole('listitem').filter((row) => row.hasAttribute('data-new'))).toHaveLength(0)

    // A live update re-fetches the history with one more bid on top.
    vi.mocked(fetchBidHistory).mockResolvedValue(page([bid(3, 25_500), bid(2, 25_000), bid(1, 24_500)]))
    await client.invalidateQueries({ queryKey: auctionKeys.bids(3) })

    const fresh = (await screen.findByText('Bidder 3')).closest('li')!
    expect(fresh).toHaveAttribute('data-new')
    expect(screen.getByText('Bidder 2').closest('li')).not.toHaveAttribute('data-new')
  })
})
