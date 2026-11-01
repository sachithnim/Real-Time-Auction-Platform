import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../../../types/api'
import { AuthContext, type AuthApi } from '../../auth/useAuth'
import { AdminDashboardPage } from '../AdminDashboardPage'
import { fetchDashboard, type AdminDashboard } from '../dashboardApi'

vi.mock('../dashboardApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../dashboardApi')>()),
  fetchDashboard: vi.fn(),
}))

const admin: User = { id: 1, first_name: 'Platform', last_name: 'Admin', name: 'Platform Admin', email: 'admin@auction.test', role: 'ADMIN' }
const soon = new Date(Date.now() + 30 * 60_000).toISOString()

const quiet: AdminDashboard = {
  auctions: { draft: 0, scheduled: 0, live: 0 },
  last_24_hours: { bids: 0, active_bidders: 0, new_bidders: 0, auctions_ended: 0, sold_value: 0 },
  attention: { open_flags: 0, unpaid_wins: 0, unpaid_sold: 0 },
  ending_soon: [],
  starting_soon: [],
  drafts: [],
  recent_activity: [],
}

function renderPage(data: AdminDashboard) {
  vi.mocked(fetchDashboard).mockResolvedValue(data)
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AuthContext.Provider value={{ user: admin, isLoading: false } as AuthApi}>
        <MemoryRouter>
          <AdminDashboardPage />
        </MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

beforeEach(() => vi.mocked(fetchDashboard).mockReset())

describe('<AdminDashboardPage>', () => {
  it('greets the admin and shows calm empty states on a quiet day', async () => {
    renderPage(quiet)

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent(/, Platform$/)
    expect(screen.getByText('Nothing needs your attention right now.')).toBeInTheDocument()
    expect(screen.getByText('No auctions are live right now.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /New auction/ })).toHaveAttribute('href', '/admin/auctions/new')
  })

  it('links each count to the page behind it', async () => {
    renderPage({ ...quiet, auctions: { draft: 2, scheduled: 5, live: 1 } })

    expect((await screen.findByText('Live now')).closest('a')).toHaveAttribute('href', '/admin/monitor')
    expect(screen.getByText('Scheduled').closest('a')).toHaveAttribute('href', '/admin/auctions?status=scheduled')
    expect(screen.getByText('Drafts').closest('a')).toHaveTextContent('2')
  })

  it('lists what needs attention, with links', async () => {
    renderPage({ ...quiet, attention: { open_flags: 3, unpaid_wins: 1, unpaid_sold: 1 } })

    expect(await screen.findByRole('link', { name: /3 suspicious bidding flags to review/ })).toHaveAttribute('href', '/admin/flags')
    expect(screen.getByRole('link', { name: /2 sold auctions are waiting for payment/ })).toBeInTheDocument()
  })

  it('warns about scheduled auctions without photos and shows recent activity', async () => {
    renderPage({
      ...quiet,
      starting_soon: [{ id: 7, title: 'Brass Telescope', bids_count: 0, images_count: 0, starting_price: 15_000, start_at: soon }],
      recent_activity: [
        { id: 1, type: 'BID_PLACED', auction: { id: 7, title: 'Brass Telescope' }, actor: 'Asha Perera', amount: 25_500, created_at: new Date().toISOString() },
      ],
    })

    const row = (await screen.findAllByRole('link', { name: 'Brass Telescope' }))[0].closest('li')!
    expect(row).toHaveTextContent('No photos')
    expect(row).toHaveTextContent('starts in 29 min')
    expect(screen.getByText('Bid placed')).toHaveTextContent('Bid placed · Rs. 25,500')
    expect(screen.getByText(/Asha Perera/)).toBeInTheDocument()
  })
})
