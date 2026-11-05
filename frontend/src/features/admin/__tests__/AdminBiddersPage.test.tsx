import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { AdminBidder, Paginated } from '../../../types/api'
import { AdminBiddersPage } from '../AdminBiddersPage'
import { disableBidder, enableBidder, fetchBidders } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchBidders: vi.fn(),
  disableBidder: vi.fn(),
  enableBidder: vi.fn(),
}))

const asha: AdminBidder = { id: 2, name: 'Asha Perera', email: 'asha@example.com', joined_at: '2026-10-01T10:00:00Z', disabled_at: null, bids_count: 7, wins_count: 2 }
const ravi: AdminBidder = { ...asha, id: 3, name: 'Ravi Kumar', email: 'ravi@example.com', disabled_at: '2026-11-03T10:00:00Z' }

function page(data: AdminBidder[]): Paginated<AdminBidder> {
  return { data, meta: { server_time: '2026-11-04T10:00:00Z', current_page: 1, last_page: 1, per_page: 20, total: data.length } }
}

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <MemoryRouter>
          <AdminBiddersPage />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchBidders).mockReset().mockResolvedValue(page([asha, ravi]))
  vi.mocked(disableBidder).mockReset()
  vi.mocked(enableBidder).mockReset()
})

const rowOf = async (name: string) => (await screen.findByText(name)).closest('tr') as HTMLElement

describe('<AdminBiddersPage>', () => {
  it('lists bidders with their activity and status', async () => {
    renderPage()

    const row = await rowOf('Asha Perera')
    expect(within(row).getByText('asha@example.com')).toBeInTheDocument()
    expect(within(row).getByText('7')).toBeInTheDocument()
    expect(within(row).getByText('Active')).toBeInTheDocument()
    expect(within(await rowOf('Ravi Kumar')).getByText('Disabled')).toBeInTheDocument()
  })

  it('asks before disabling, then disables', async () => {
    vi.mocked(disableBidder).mockResolvedValue({ ...asha, disabled_at: '2026-11-04T10:00:00Z' })
    const user = userEvent.setup()
    renderPage()

    const row = await rowOf('Asha Perera')
    await user.click(within(row).getByRole('button', { name: 'Disable' }))
    expect(disableBidder).not.toHaveBeenCalled()

    await user.click(within(row).getByRole('button', { name: 'Yes, disable' }))

    expect(disableBidder).toHaveBeenCalledWith(2)
    expect(await screen.findByText('Asha Perera is disabled')).toBeInTheDocument()
  })

  it('keeping the account changes nothing', async () => {
    const user = userEvent.setup()
    renderPage()

    const row = await rowOf('Asha Perera')
    await user.click(within(row).getByRole('button', { name: 'Disable' }))
    await user.click(within(row).getByRole('button', { name: 'Keep' }))

    expect(within(row).getByRole('button', { name: 'Disable' })).toBeInTheDocument()
    expect(disableBidder).not.toHaveBeenCalled()
  })

  it('enables a disabled bidder in one click', async () => {
    vi.mocked(enableBidder).mockResolvedValue({ ...ravi, disabled_at: null })
    const user = userEvent.setup()
    renderPage()

    await user.click(within(await rowOf('Ravi Kumar')).getByRole('button', { name: 'Enable' }))

    expect(enableBidder).toHaveBeenCalledWith(3)
    expect(await screen.findByText('Ravi Kumar can log in again')).toBeInTheDocument()
  })

  it('searches and filters through the API', async () => {
    const user = userEvent.setup()
    renderPage()
    await screen.findByText('Asha Perera')

    await user.type(screen.getByRole('searchbox', { name: 'Search bidders' }), 'ravi{Enter}')
    expect(fetchBidders).toHaveBeenLastCalledWith('all', 'ravi', 1)

    await user.click(screen.getByRole('tab', { name: 'Disabled' }))
    expect(fetchBidders).toHaveBeenLastCalledWith('disabled', 'ravi', 1)
  })
})
