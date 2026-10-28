import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Auction } from '../../../types/api'
import { placeBid } from '../api'
import { BidForm } from '../BidForm'
import { bidSchema } from '../bidSchema'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  placeBid: vi.fn(),
}))

const auction = {
  id: 3, status: 'LIVE', starting_price: 20_000, current_price: 25_000, min_next_bid: 25_500, sequence: 4,
} as Auction

function renderForm(overrides: Partial<Auction> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <BidForm auction={{ ...auction, ...overrides }} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

function apiError(status: number, code: string, details: object = {}) {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError('failed', 'ERR', config, null, {
    status, statusText: '', headers: {}, config, data: { error: { code, message: code, details } },
  })
}

beforeEach(() => {
  vi.mocked(placeBid).mockReset()
})

describe('bidSchema', () => {
  const schema = bidSchema(25_500)

  it('accepts the minimum and above', () => {
    expect(schema.safeParse({ amount: 25_500 }).success).toBe(true)
    expect(schema.safeParse({ amount: 30_000 }).success).toBe(true)
  })

  it.each([
    [25_499, 'Your bid must be at least Rs. 25,500.'],
    [25_500.5, 'Enter a whole number of rupees.'],
    [Number.NaN, 'Enter an amount in rupees.'],
    [2_000_000_000_000, 'That amount is too large.'],
  ])('rejects %s', (amount, message) => {
    const result = schema.safeParse({ amount })
    expect(result.success).toBe(false)
    expect(result.error?.issues[0].message).toBe(message)
  })
})

describe('<BidForm>', () => {
  it('suggests the minimum next bid from the server', () => {
    renderForm()

    expect(screen.getByLabelText('Your bid (Rs.)')).toHaveValue(25_500)
    expect(screen.getByText('Minimum Rs. 25,500')).toBeInTheDocument()
  })

  it('blocks a bid below the minimum without calling the server', async () => {
    renderForm()
    const input = screen.getByLabelText('Your bid (Rs.)')

    await userEvent.clear(input)
    await userEvent.type(input, '25000')
    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    expect(await screen.findByText('Your bid must be at least Rs. 25,500.')).toBeInTheDocument()
    expect(placeBid).not.toHaveBeenCalled()
  })

  it('blocks an empty amount', async () => {
    renderForm()

    await userEvent.clear(screen.getByLabelText('Your bid (Rs.)'))
    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    expect(await screen.findByText('Enter an amount in rupees.')).toBeInTheDocument()
    expect(placeBid).not.toHaveBeenCalled()
  })

  it('submits once even when clicked repeatedly', async () => {
    let resolve!: (value: Awaited<ReturnType<typeof placeBid>>) => void
    vi.mocked(placeBid).mockReturnValue(new Promise((r) => (resolve = r)))
    renderForm()
    const button = screen.getByRole('button', { name: 'Place bid' })

    await userEvent.click(button)
    await userEvent.click(screen.getByRole('button', { name: 'Placing bid…' }))

    expect(placeBid).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: 'Placing bid…' })).toBeDisabled()

    resolve({ bid: { id: 1, amount: 25_500, type: 'MANUAL', created_at: '' }, auction: { ...auction, current_price: 25_500, is_leading: true, sequence: 7 } })
    await waitFor(() => expect(screen.getByRole('button', { name: 'Place bid' })).toBeEnabled())
  })

  it('retries a lost request with the same idempotency key', async () => {
    vi.mocked(placeBid)
      .mockRejectedValueOnce(new AxiosError('Network Error', 'ERR_NETWORK'))
      .mockResolvedValueOnce({ bid: { id: 1, amount: 25_500, type: 'MANUAL', created_at: '' }, auction: { ...auction, is_leading: true } })
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    await waitFor(() => expect(placeBid).toHaveBeenCalledTimes(2))
    const [firstKey, secondKey] = vi.mocked(placeBid).mock.calls.map((call) => call[2])
    expect(firstKey).toBe(secondKey)
    expect(await screen.findByText('Bid placed')).toBeInTheDocument()
  })

  it('uses a fresh key for the next bid', async () => {
    vi.mocked(placeBid).mockResolvedValue({ bid: { id: 1, amount: 25_500, type: 'MANUAL', created_at: '' }, auction: { ...auction, is_leading: false } })
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))
    await waitFor(() => expect(placeBid).toHaveBeenCalledTimes(1))
    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))
    await waitFor(() => expect(placeBid).toHaveBeenCalledTimes(2))

    const keys = vi.mocked(placeBid).mock.calls.map((call) => call[2])
    expect(keys[0]).not.toBe(keys[1])
  })

  it('shows a friendly message when the server says the bid is too low', async () => {
    vi.mocked(placeBid).mockRejectedValue(apiError(422, 'BID_TOO_LOW', { min_next_bid: 26_000 }))
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    expect(await screen.findByText('Your bid is too low. The minimum is now Rs. 26,000.')).toBeInTheDocument()
  })

  it('tells the bidder when the auction has ended', async () => {
    vi.mocked(placeBid).mockRejectedValue(apiError(409, 'AUCTION_ENDED'))
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    expect(await screen.findByText('This auction has ended.')).toBeInTheDocument()
    expect(placeBid).toHaveBeenCalledTimes(1)
  })

  it('reads the minimum to screen readers with the input, and the error once there is one', async () => {
    renderForm()
    const input = screen.getByLabelText('Your bid (Rs.)')
    expect(input).toHaveAccessibleDescription('Minimum Rs. 25,500')

    await userEvent.clear(input)
    await userEvent.type(input, '100')
    await userEvent.click(screen.getByRole('button', { name: 'Place bid' }))

    expect(await screen.findByText('Your bid must be at least Rs. 25,500.')).toBeInTheDocument()
    expect(input).toHaveAccessibleDescription('Your bid must be at least Rs. 25,500.')
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('offers quick amounts from the minimum, one step apart', () => {
    renderForm()

    const quick = within(screen.getByRole('group', { name: 'Quick amounts' }))
    expect(quick.getAllByRole('button').map((b) => b.textContent)).toEqual(['Rs. 25,500', 'Rs. 26,000', 'Rs. 26,500'])
    expect(quick.getByRole('button', { name: 'Rs. 25,500' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('fills the box with a quick amount without placing the bid', async () => {
    renderForm()

    await userEvent.click(screen.getByRole('button', { name: 'Rs. 26,500' }))

    expect(screen.getByLabelText('Your bid (Rs.)')).toHaveValue(26_500)
    expect(screen.getByRole('button', { name: 'Rs. 26,500' })).toHaveAttribute('aria-pressed', 'true')
    expect(placeBid).not.toHaveBeenCalled()
  })
})
