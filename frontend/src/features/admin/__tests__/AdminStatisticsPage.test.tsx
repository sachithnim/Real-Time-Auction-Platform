import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuctionStatistics } from '../../../types/api'
import { AdminStatisticsPage } from '../AdminStatisticsPage'
import { fetchStatistics } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchStatistics: vi.fn(),
}))

const busy: AuctionStatistics = {
  auctions: { total: 8, by_status: { DRAFT: 1, SCHEDULED: 1, LIVE: 1, COMPLETED: 4, CANCELLED: 1 } },
  results: { completed: 3, sold: 2, reserve_not_met: 1, no_bids: 0, sell_through_percent: 66.7 },
  revenue: { total: 80_000, average: 40_000, highest: 50_000 },
  bids: { total: 8, manual: 6, auto: 2, bidders: 3, auctions_with_bids: 4, average_per_auction: 2 },
  anti_sniping: { extended_auctions: 2, total_extensions: 3 },
  top_bidders: [{ id: 7, name: 'Asha Perera', email: 'asha@example.com', bids: 4, auctions: 3, wins: 1 }],
}

const empty: AuctionStatistics = {
  auctions: { total: 0, by_status: { DRAFT: 0, SCHEDULED: 0, LIVE: 0, COMPLETED: 0, CANCELLED: 0 } },
  results: { completed: 0, sold: 0, reserve_not_met: 0, no_bids: 0, sell_through_percent: null },
  revenue: { total: 0, average: null, highest: null },
  bids: { total: 0, manual: 0, auto: 0, bidders: 0, auctions_with_bids: 0, average_per_auction: null },
  anti_sniping: { extended_auctions: 0, total_extensions: 0 },
  top_bidders: [],
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <AdminStatisticsPage />
    </QueryClientProvider>,
  )
}

/** The value shown on the summary tile with this label. */
async function tileValue(label: string) {
  const tile = (await screen.findByText(label)).parentElement!
  return within(tile)
}

beforeEach(() => {
  vi.mocked(fetchStatistics).mockReset()
})

describe('<AdminStatisticsPage>', () => {
  it('shows the platform figures in rupees and percentages', async () => {
    vi.mocked(fetchStatistics).mockResolvedValue(busy)
    renderPage()

    expect((await tileValue('Sell-through')).getByText('66.7%')).toBeInTheDocument()
    expect((await tileValue('Revenue')).getByText('Rs. 80,000')).toBeInTheDocument()
    expect((await tileValue('Average sale')).getByText('Rs. 40,000')).toBeInTheDocument()
    expect(screen.getByText('2 of 3 completed sold')).toBeInTheDocument()
    expect(screen.getByText('6 / 2')).toBeInTheDocument()

    const row = screen.getByText('Asha Perera').closest('tr')!
    expect(within(row).getByText('asha@example.com')).toBeInTheDocument()
    expect(within(row).getAllByRole('cell').map((cell) => cell.textContent)).toEqual(['Asha Pereraasha@example.com', '4', '3', '1'])
  })

  it('shows dashes instead of averages before anything has been measured', async () => {
    vi.mocked(fetchStatistics).mockResolvedValue(empty)
    renderPage()

    expect((await tileValue('Sell-through')).getByText('—')).toBeInTheDocument()
    expect((await tileValue('Average sale')).getByText('—')).toBeInTheDocument()
    expect(screen.getByText('No bids yet.')).toBeInTheDocument()
  })

  it('explains a failed load in plain words', async () => {
    vi.mocked(fetchStatistics).mockRejectedValue(Object.assign(new Error('Network Error'), { isAxiosError: true, code: 'ERR_NETWORK' }))
    renderPage()

    expect(await screen.findByRole('alert')).toHaveTextContent("Can't reach the server.")
  })
})
