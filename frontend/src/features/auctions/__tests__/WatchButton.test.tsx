import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Auction, User } from '../../../types/api'
import { AuthContext, type AuthApi } from '../../auth/useAuth'
import { auctionKeys, unwatchAuction, watchAuction } from '../api'
import { WatchButton } from '../WatchButton'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  watchAuction: vi.fn(),
  unwatchAuction: vi.fn(),
}))

const bidder: User = { id: 5, name: 'Bidder 1', email: 'bidder1@auction.test', role: 'BIDDER' }
const admin: User = { id: 1, name: 'Admin', email: 'admin@auction.test', role: 'ADMIN' }

/** Reads the auction from the query cache, the way the real pages do, so updates re-render. */
function CachedWatchButton({ initial }: { initial: Auction }) {
  const { data } = useQuery({ queryKey: auctionKeys.detail(initial.id), queryFn: () => initial, initialData: initial, staleTime: Infinity })
  return <WatchButton auction={data} />
}

function renderButton(user: User | null, isWatching = false, onCardClick = vi.fn()) {
  const auth = { user, isLoading: false } as AuthApi
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const auction = { id: 3, title: 'Kandyan Mask', is_watching: isWatching } as Auction

  render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <AuthContext.Provider value={auth}>
          <div onClick={onCardClick}>
            <CachedWatchButton initial={auction} />
          </div>
        </AuthContext.Provider>
      </ToastProvider>
    </QueryClientProvider>,
  )
  return onCardClick
}

beforeEach(() => {
  vi.mocked(watchAuction).mockReset()
  vi.mocked(unwatchAuction).mockReset()
})

describe('<WatchButton>', () => {
  it('is only offered to bidders', () => {
    renderButton(null)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('is not offered to admins', () => {
    renderButton(admin)
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('adds the auction to the watchlist without opening the auction', async () => {
    vi.mocked(watchAuction).mockResolvedValue(undefined)
    const onCardClick = renderButton(bidder)

    await userEvent.click(screen.getByRole('button', { name: /watch/i }))

    expect(watchAuction).toHaveBeenCalledWith(3)
    expect(await screen.findByRole('button', { name: /watching/i })).toHaveAttribute('aria-pressed', 'true')
    expect(onCardClick).not.toHaveBeenCalled()
  })

  it('removes a watched auction', async () => {
    vi.mocked(unwatchAuction).mockResolvedValue(undefined)
    renderButton(bidder, true)

    await userEvent.click(screen.getByRole('button', { name: /watching/i }))

    expect(unwatchAuction).toHaveBeenCalledWith(3)
    // The star is decorative (aria-hidden), so the accessible name is just "Watch".
    expect(await screen.findByRole('button', { name: /^watch$/i })).toHaveAttribute('aria-pressed', 'false')
  })

  it('keeps the old state and explains when the change fails', async () => {
    vi.mocked(watchAuction).mockRejectedValue(Object.assign(new Error('Network Error'), { isAxiosError: true, code: 'ERR_NETWORK' }))
    renderButton(bidder)

    await userEvent.click(screen.getByRole('button', { name: /watch/i }))

    expect(await screen.findByText('Your watchlist was not updated')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /watch/i })).toHaveAttribute('aria-pressed', 'false')
  })
})
