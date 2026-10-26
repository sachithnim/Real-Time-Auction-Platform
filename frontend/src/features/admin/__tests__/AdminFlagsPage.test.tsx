import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { BidFlag, Paginated } from '../../../types/api'
import { AdminFlagsPage } from '../AdminFlagsPage'
import { fetchFlags, reviewFlag } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchFlags: vi.fn(),
  reviewFlag: vi.fn(),
}))

const sharedIp: BidFlag = {
  id: 9,
  rule: 'SHARED_IP',
  status: 'OPEN',
  auction: { id: 4, title: 'Hand-Carved Kandyan Mask' },
  bidders: [
    { id: 2, name: 'Asha Perera', email: 'asha@example.com' },
    { id: 3, name: 'Nimal Silva', email: 'nimal@example.com' },
  ],
  details: { ip_addresses: ['203.0.113.7'] },
  occurrences: 3,
  first_detected_at: '2026-10-27T10:00:00Z',
  last_detected_at: '2026-10-27T10:05:00Z',
  reviewed_by: null,
  reviewed_at: null,
}

function page(flags: BidFlag[], openCount = flags.length): Paginated<BidFlag> & { meta: { open_count: number } } {
  return {
    data: flags,
    meta: { current_page: 1, last_page: 1, per_page: 100, total: flags.length, server_time: '', open_count: openCount },
  } as unknown as Paginated<BidFlag> & { meta: { open_count: number } }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter>
          <AdminFlagsPage />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchFlags).mockReset()
  vi.mocked(reviewFlag).mockReset()
})

describe('<AdminFlagsPage>', () => {
  it('shows an open flag with the accounts, the evidence and how often it was seen', async () => {
    vi.mocked(fetchFlags).mockResolvedValue(page([sharedIp]))
    renderPage()

    const card = within((await screen.findByRole('heading', { name: 'Shared IP address' })).closest('div.rounded-lg')! as HTMLElement)
    expect(card.getByText('Asha Perera')).toBeInTheDocument()
    expect(card.getByText('nimal@example.com')).toBeInTheDocument()
    expect(card.getByText('Same IP: 203.0.113.7')).toBeInTheDocument()
    expect(card.getByText(/^3 times/)).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Open (1)' })).toHaveAttribute('aria-selected', 'true')
  })

  it('marks a flag as reviewed', async () => {
    vi.mocked(fetchFlags).mockResolvedValue(page([sharedIp]))
    vi.mocked(reviewFlag).mockResolvedValue({ ...sharedIp, status: 'REVIEWED' })
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Mark reviewed' }))

    expect(reviewFlag).toHaveBeenCalledWith(9)
    expect(await screen.findByText('Marked as reviewed')).toBeInTheDocument()
  })

  it('says when there is nothing to review', async () => {
    vi.mocked(fetchFlags).mockResolvedValue(page([], 0))
    renderPage()

    expect(await screen.findByText('Nothing to review. No suspicious bidding has been flagged.')).toBeInTheDocument()
  })
})
