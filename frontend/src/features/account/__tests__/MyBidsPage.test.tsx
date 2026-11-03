import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MyBidCounts } from '../../../types/api'
import { fetchMyBids, type MyBidsPage as MyBidsResponse } from '../myBids'
import { MyBidsPage } from '../MyBidsPage'
import { myBidAuction } from './myBidAuction'

vi.mock('../myBids', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../myBids')>()),
  fetchMyBids: vi.fn(),
}))

// No WebSocket in tests: the page must still render from the REST response.
vi.mock('../../../lib/echo', () => ({ getEcho: () => null, onConnectionChange: () => () => {} }))

const noCounts: MyBidCounts = { all: 0, active: 0, won: 0, lost: 0, to_pay: 0 }

function page(data: MyBidsResponse['data'], counts: Partial<MyBidCounts> = {}): MyBidsResponse {
  return {
    data,
    counts: { ...noCounts, all: data.length, ...counts },
    meta: { server_time: '2026-11-03T10:00:00Z', current_page: 1, last_page: 1, per_page: 10, total: data.length },
  }
}

function renderAt(path = '/account/bids') {
  const router = createMemoryRouter(
    [
      { path: '/account/bids', element: <MyBidsPage /> },
      { path: '/auctions/:id', element: <p>Auction page</p> },
      { path: '/', element: <p>Auction list</p> },
    ],
    { initialEntries: [path] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
  return router
}

beforeEach(() => {
  vi.mocked(fetchMyBids).mockReset()
})

describe('<MyBidsPage>', () => {
  it('shows each auction with how the bidder stands and their own numbers', async () => {
    vi.mocked(fetchMyBids).mockResolvedValue(
      page([
        myBidAuction({}, { max_amount: 20_000, max_active: true }),
        myBidAuction({ id: 8, title: 'Silver Pocket Watch', is_leading: false, leader: 'Bidder 2' }, { state: 'OUTBID', highest_bid: 9_000 }),
      ]),
    )
    renderAt()

    const rows = await screen.findAllByRole('listitem')
    expect(rows).toHaveLength(2)

    expect(within(rows[0]).getByText('You’re leading')).toBeInTheDocument()
    expect(within(rows[0]).getByText('Your maximum')).toBeInTheDocument()
    expect(within(rows[0]).getByText(/only you see this/)).toBeInTheDocument()

    expect(within(rows[1]).getByText('Outbid')).toBeInTheDocument()
    expect(within(rows[1]).getByText('Rs. 9,000')).toBeInTheDocument()
    expect(within(rows[1]).queryByText('Your maximum')).not.toBeInTheDocument()
    expect(within(rows[1]).getByRole('link', { name: 'Bid again: Silver Pocket Watch' })).toHaveAttribute('href', '/auctions/8')
  })

  it('shows the count on every tab and switches tab through the URL', async () => {
    vi.mocked(fetchMyBids).mockResolvedValue(page([myBidAuction()], { all: 5, active: 2, won: 2, lost: 1 }))
    const user = userEvent.setup()
    const router = renderAt()

    expect(await screen.findByRole('tab', { name: 'Won 2' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'All 5' })).toHaveAttribute('aria-selected', 'true')

    await user.click(screen.getByRole('tab', { name: 'Won 2' }))

    expect(router.state.location.search).toBe('?filter=won')
    expect(fetchMyBids).toHaveBeenLastCalledWith('won', 1)
  })

  it('points out wins waiting for payment', async () => {
    const won = myBidAuction({ status: 'COMPLETED', result: 'SOLD', is_winner: true, ended_at: '2026-11-02T10:00:00Z' }, { state: 'WON', payment_status: 'PENDING' })
    vi.mocked(fetchMyBids).mockResolvedValue(page([won], { won: 1, to_pay: 1 }))
    const user = userEvent.setup()
    const router = renderAt()

    expect(await screen.findByText('You won an auction!')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Pay now: Brass Ship Telescope' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'See my wins' }))
    expect(router.state.location.search).toBe('?filter=won')
  })

  it('invites a bidder with no bids to browse live auctions', async () => {
    vi.mocked(fetchMyBids).mockResolvedValue(page([]))
    renderAt()

    expect(await screen.findByText('You haven’t bid on anything yet')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Browse live auctions' })).toHaveAttribute('href', '/?status=LIVE')
  })

  it('an empty tab offers to show all bids again', async () => {
    vi.mocked(fetchMyBids).mockResolvedValue(page([], { all: 3 }))
    const user = userEvent.setup()
    const router = renderAt('/account/bids?filter=won')

    expect(await screen.findByText('No wins yet')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Show all my bids' }))

    expect(router.state.location.search).toBe('')
  })

  it('shows an error with a retry when the list fails to load', async () => {
    vi.mocked(fetchMyBids).mockRejectedValueOnce(new Error('Network Error')).mockResolvedValue(page([myBidAuction()]))
    const user = userEvent.setup()
    renderAt()

    expect(await screen.findByText('Could not load your bids')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /try again|retry/i }))

    expect(await screen.findByText('Brass Ship Telescope')).toBeInTheDocument()
  })
})
