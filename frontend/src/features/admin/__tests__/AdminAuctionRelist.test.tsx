import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { AdminAuction } from '../../../types/api'
import { AdminAuctionDetailPage } from '../AdminAuctionDetailPage'
import { fetchAdminAuction, fetchAuctionEvents, relistAuction } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchAdminAuction: vi.fn(),
  fetchAuctionEvents: vi.fn(),
  relistAuction: vi.fn(),
}))
vi.mock('../../../lib/echo', () => ({ getEcho: () => null, onConnectionChange: () => () => {} }))

const unsold = {
  id: 9, title: 'Brass Telescope', status: 'COMPLETED', result: 'RESERVE_NOT_MET', category: null, payment: null,
  current_price: 25_000, reserve_price: 30_000, final_price: null, bids_count: 3, images: [],
} as unknown as AdminAuction

function renderPage(auction: AdminAuction) {
  vi.mocked(fetchAdminAuction).mockResolvedValue(auction)
  const router = createMemoryRouter(
    [
      { path: '/admin/auctions/:id', element: <AdminAuctionDetailPage /> },
      { path: '/admin/auctions/:id/edit', element: <p>Edit form</p> },
    ],
    { initialEntries: [`/admin/auctions/${auction.id}`] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
  return router
}

beforeEach(() => {
  vi.mocked(fetchAuctionEvents).mockReset().mockResolvedValue({ data: [], meta: { users: {}, current_page: 1, last_page: 1, per_page: 50, total: 0, server_time: '' } } as never)
  vi.mocked(relistAuction).mockReset()
})

describe('<AdminAuctionDetailPage> relist', () => {
  it('relists an unsold auction and opens the new draft to check it', async () => {
    vi.mocked(relistAuction).mockResolvedValue({ ...unsold, id: 17, status: 'DRAFT', result: null } as AdminAuction)
    const router = renderPage(unsold)

    await userEvent.click(await screen.findByRole('button', { name: 'Relist as new draft' }))

    expect(relistAuction).toHaveBeenCalledWith(9)
    expect(await screen.findByText('Edit form')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/admin/auctions/17/edit')
  })

  it('is offered for cancelled auctions but never for sold or open ones', async () => {
    renderPage({ ...unsold, status: 'COMPLETED', result: 'SOLD' } as AdminAuction)
    await screen.findByRole('heading', { name: /Brass Telescope/ })
    expect(screen.queryByRole('button', { name: 'Relist as new draft' })).not.toBeInTheDocument()
  })
})
