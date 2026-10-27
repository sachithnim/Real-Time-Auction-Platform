import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Auction } from '../../../types/api'
import { fetchMyAutoBid, setAutoBid } from '../api'
import { AutoBidForm } from '../AutoBidForm'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchMyAutoBid: vi.fn(),
  setAutoBid: vi.fn(),
}))

const auction = { id: 3, status: 'LIVE', starting_price: 10_000, current_price: 15_000, min_next_bid: 15_500, sequence: 2 } as Auction

function renderForm() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AutoBidForm auction={auction} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchMyAutoBid).mockReset()
  vi.mocked(setAutoBid).mockReset()
})

describe('<AutoBidForm>', () => {
  it('requires a maximum of at least the next valid bid', async () => {
    vi.mocked(fetchMyAutoBid).mockResolvedValue(null)
    renderForm()

    await userEvent.type(screen.getByLabelText('Maximum (Rs.)'), '15000')
    await userEvent.click(screen.getByRole('button', { name: 'Start auto-bidding' }))

    expect(await screen.findByText('Your maximum must be at least Rs. 15,500.')).toBeInTheDocument()
    expect(setAutoBid).not.toHaveBeenCalled()
  })

  it('shows the bidder their own active maximum and never allows lowering it', async () => {
    vi.mocked(fetchMyAutoBid).mockResolvedValue({ max_amount: 20_000, is_active: true, max_set_at: '' })
    renderForm()

    expect(await screen.findByText('Rs. 20,000')).toBeInTheDocument()
    expect(screen.getByText('More than your current Rs. 20,000')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Raise maximum to (Rs.)'), '18000')
    await userEvent.click(screen.getByRole('button', { name: 'Raise maximum' }))

    expect(await screen.findByText('Your new maximum must be more than Rs. 20,000.')).toBeInTheDocument()
    expect(setAutoBid).not.toHaveBeenCalled()
  })

  it('treats "raising" to the same maximum as no raise at all', async () => {
    vi.mocked(fetchMyAutoBid).mockResolvedValue({ max_amount: 20_000, is_active: true, max_set_at: '' })
    renderForm()

    await userEvent.type(await screen.findByLabelText('Raise maximum to (Rs.)'), '20000')
    await userEvent.click(screen.getByRole('button', { name: 'Raise maximum' }))

    expect(await screen.findByText('Your new maximum must be more than Rs. 20,000.')).toBeInTheDocument()
    expect(setAutoBid).not.toHaveBeenCalled()
  })

  it('sends a valid maximum with an idempotency key', async () => {
    vi.mocked(fetchMyAutoBid).mockResolvedValue(null)
    vi.mocked(setAutoBid).mockResolvedValue({
      auto_bid: { max_amount: 20_000, is_active: true, max_set_at: '' },
      auction: { ...auction, current_price: 15_500, is_leading: true, sequence: 5 },
    })
    renderForm()

    await userEvent.type(screen.getByLabelText('Maximum (Rs.)'), '20000')
    await userEvent.click(screen.getByRole('button', { name: 'Start auto-bidding' }))

    expect(await screen.findByText('Auto-bid set')).toBeInTheDocument()
    expect(setAutoBid).toHaveBeenCalledWith(3, 20_000, expect.stringMatching(/^[0-9a-f-]{36}$/))
  })
})
