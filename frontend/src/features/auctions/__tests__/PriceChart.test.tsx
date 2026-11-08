import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchPriceHistory } from '../api'
import { PriceChart } from '../PriceChart'
import type { PriceHistory } from '../priceChartModel'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchPriceHistory: vi.fn(),
}))

const history: PriceHistory = {
  starting_price: 40_000,
  start_at: '2026-10-25T10:00:00Z',
  end_at: '2026-10-27T10:00:00Z',
  points: [
    { amount: 40_000, type: 'MANUAL', at: '2026-10-25T11:00:00Z', mine: true },
    { amount: 44_500, type: 'AUTO', at: '2026-10-26T09:00:00Z', mine: false },
    { amount: 50_000, type: 'MANUAL', at: '2026-10-27T08:00:00Z', mine: false },
  ],
}

function renderChart(status: 'LIVE' | 'COMPLETED' = 'COMPLETED') {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <PriceChart auctionId={12} status={status} />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchPriceHistory).mockReset().mockResolvedValue(history)
})

describe('<PriceChart>', () => {
  it('describes the whole chart for screen readers and keys every kind of dot', async () => {
    renderChart()

    expect(await screen.findByRole('img', { name: /Price history: 3 bids, from Rs\. 40,000 to Rs\. 50,000\./ })).toBeInTheDocument()
    const key = screen.getByRole('list', { name: 'Key' })
    expect(key).toHaveTextContent('Bid')
    expect(key).toHaveTextContent('Automatic bid')
    expect(key).toHaveTextContent('Your bid')
  })

  it('shows the latest bid on focus and steps through bids with the arrow keys', async () => {
    renderChart()
    const chart = await screen.findByRole('img', { name: /Price history/ })

    fireEvent.focus(chart)
    expect(screen.getByRole('status')).toHaveTextContent('Rs. 50,000')

    fireEvent.keyDown(chart, { key: 'ArrowLeft' })
    expect(screen.getByRole('status')).toHaveTextContent('Rs. 44,500Automatic bid')

    fireEvent.keyDown(chart, { key: 'Home' })
    expect(screen.getByRole('status')).toHaveTextContent('Rs. 40,000Your bid')

    fireEvent.keyDown(chart, { key: 'Escape' })
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('follows the pointer to the nearest bid', async () => {
    renderChart()
    const chart = await screen.findByRole('img', { name: /Price history/ })

    fireEvent.pointerMove(chart, { clientX: 0 })
    expect(screen.getByRole('status')).toHaveTextContent('Rs. 40,000')

    fireEvent.pointerLeave(chart)
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('runs to "Now" while the auction is live', async () => {
    renderChart('LIVE')

    expect(await screen.findByText('Now')).toBeInTheDocument()
  })

  it('shows nothing before the first bid', async () => {
    vi.mocked(fetchPriceHistory).mockResolvedValue({ ...history, points: [] })
    const { container } = render(
      <QueryClientProvider client={new QueryClient()}>
        <PriceChart auctionId={12} status="LIVE" />
      </QueryClientProvider>,
    )

    await vi.waitFor(() => expect(fetchPriceHistory).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
