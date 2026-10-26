import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MonitorSnapshot } from '../../../types/api'
import { AdminMonitorPage } from '../AdminMonitorPage'
import { fetchMonitor } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchMonitor: vi.fn(),
}))

// No WebSocket in tests: the page must still render from the REST snapshot.
vi.mock('../../../lib/echo', () => ({ getEcho: () => null, onConnectionChange: () => () => {} }))

const busy: MonitorSnapshot = {
  auctions: [
    {
      id: 4,
      title: 'Hand-Carved Kandyan Mask',
      status: 'LIVE',
      starting_price: 5_000,
      current_price: 15_500,
      reserve_price: 20_000,
      reserve_met: false,
      min_next_bid: 16_000,
      leader: { id: 2, name: 'Asha Perera', email: 'asha@example.com', pseudonym: 'Bidder 1' },
      bids_count: 3,
      recent_bids: 2,
      active_auto_bids: 1,
      end_at: '2099-01-01T00:00:00Z',
      original_end_at: '2099-01-01T00:00:00Z',
      extensions_count: 1,
      max_extensions: 3,
      anti_snipe_seconds: 120,
      sequence: 7,
    },
  ],
  recent_rejections: [
    {
      id: 31,
      auction_id: 4,
      auction_title: 'Hand-Carved Kandyan Mask',
      bidder: { id: 3, name: 'Nimal Silva', email: 'nimal@example.com' },
      action: 'BID',
      amount: 15_000,
      reason: 'BID_TOO_LOW',
      message: 'Your bid must be at least 16000.',
      created_at: '2026-10-26T10:00:00.000Z',
    },
  ],
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <AdminMonitorPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchMonitor).mockReset()
})

describe('<AdminMonitorPage>', () => {
  it('shows each running auction with its leader, reserve and activity', async () => {
    vi.mocked(fetchMonitor).mockResolvedValue(busy)
    renderPage()

    const auctionRow = (await screen.findByRole('link', { name: '#4 Hand-Carved Kandyan Mask' })).closest('tr')!
    const cells = within(auctionRow)
    expect(cells.getByText('Rs. 15,500')).toBeInTheDocument()
    expect(cells.getByText('Rs. 20,000 · not met')).toBeInTheDocument()
    expect(cells.getByText('Asha Perera')).toBeInTheDocument()
    expect(cells.getByText('asha@example.com · shown as Bidder 1')).toBeInTheDocument()
    expect(cells.getByText('2 in 5 min')).toBeInTheDocument()
    expect(cells.getByText('Extended 1 of 3')).toBeInTheDocument()
  })

  it('lists rejected bids with the bidder and a readable reason', async () => {
    vi.mocked(fetchMonitor).mockResolvedValue(busy)
    renderPage()

    const item = (await screen.findByText('Nimal Silva')).closest('li')!
    expect(within(item).getByText('Below the minimum')).toBeInTheDocument()
    expect(item).toHaveTextContent('bid Rs. 15,000 on Hand-Carved Kandyan Mask')
  })

  it('says so when nothing is live', async () => {
    vi.mocked(fetchMonitor).mockResolvedValue({ auctions: [], recent_rejections: [] })
    renderPage()

    expect(await screen.findByText('No auctions are live right now.')).toBeInTheDocument()
    expect(screen.getByText('No rejected bids.')).toBeInTheDocument()
  })
})
