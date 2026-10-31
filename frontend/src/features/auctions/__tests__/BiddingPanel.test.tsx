import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Auction, User } from '../../../types/api'
import { AuthContext, type AuthApi } from '../../auth/useAuth'
import { fetchMyAutoBid, placeBid } from '../api'
import { BiddingPanel } from '../BiddingPanel'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchMyAutoBid: vi.fn(),
  placeBid: vi.fn(),
}))

const bidder: User = { id: 5, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'bidder1@auction.test', role: 'BIDDER' }

const live = {
  id: 3, status: 'LIVE', starting_price: 20_000, current_price: 25_000, min_next_bid: 25_500, min_increment: 100,
  is_leading: false, my_pseudonym: null, sequence: 4,
} as unknown as Auction

function renderPanel(overrides: Partial<Auction>, user: User | null = bidder) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AuthContext.Provider value={{ user, isLoading: false } as AuthApi}>
          <MemoryRouter>
            <BiddingPanel auction={{ ...live, ...overrides }} />
          </MemoryRouter>
        </AuthContext.Provider>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const banner = () => screen.queryByText('You have been outbid.')

beforeEach(() => {
  vi.mocked(fetchMyAutoBid).mockReset().mockResolvedValue(null)
})

describe('<BiddingPanel> outbid banner', () => {
  it('tells a bidder who was overtaken what to bid to lead again', () => {
    renderPanel({ my_pseudonym: 'Bidder 2' })

    expect(banner()).toBeInTheDocument()
    expect(banner()!.closest('[role="status"]')).toHaveTextContent('The price is now Rs. 25,000. Bid Rs. 25,500 or more to lead again.')
  })

  it('places a bid at the new minimum in one tap', async () => {
    vi.mocked(placeBid).mockResolvedValue({ auction: { ...live, is_leading: true, current_price: 25_500, min_next_bid: 26_000, sequence: 5 } } as Awaited<ReturnType<typeof placeBid>>)
    renderPanel({ my_pseudonym: 'Bidder 2' })

    await userEvent.click(screen.getByRole('button', { name: 'Bid Rs. 25,500 now' }))

    expect(placeBid).toHaveBeenCalledOnce()
    expect(vi.mocked(placeBid).mock.calls[0].slice(0, 2)).toEqual([3, 25_500])
  })

  it('stays hidden for someone who has not bid yet', () => {
    renderPanel({ my_pseudonym: null })

    expect(banner()).toBeNull()
  })

  it('stays hidden for the current leader', () => {
    renderPanel({ my_pseudonym: 'Bidder 2', is_leading: true })

    expect(banner()).toBeNull()
    expect(screen.getByText(/You are the highest bidder/)).toBeInTheDocument()
  })
})
