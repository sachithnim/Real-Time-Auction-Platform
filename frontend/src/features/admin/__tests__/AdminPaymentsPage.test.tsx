import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AdminPayment } from '../../../types/api'
import { AdminPaymentsPage } from '../AdminPaymentsPage'
import { fetchPayments } from '../api'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchPayments: vi.fn(),
}))

const declined: AdminPayment = {
  id: 1,
  auction: { id: 7, title: 'Raksha Mask' },
  bidder: { id: 2, name: 'Nimal Silva', email: 'nimal@example.com' },
  amount: 9_500,
  status: 'FAILED',
  attempts: 1,
  card_brand: 'Visa',
  card_last4: '0002',
  reference: null,
  failure_reason: 'Card declined',
  paid_at: null,
  created_at: '2026-11-01T10:00:00Z',
}

beforeEach(() => {
  vi.mocked(fetchPayments).mockReset().mockResolvedValue({
    data: [declined],
    counts: { all: 3, pending: 1, failed: 1, paid: 1, paid_total: 20_000, owed_total: 68_500 },
    meta: { server_time: '2026-11-04T10:00:00Z', current_page: 1, last_page: 1, per_page: 20, total: 1 },
  })
})

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <AdminPaymentsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('<AdminPaymentsPage>', () => {
  it('shows what was received, what is owed, and each payment', async () => {
    renderPage()

    expect(await screen.findByText('Rs. 20,000')).toBeInTheDocument()
    expect(screen.getByText('Rs. 68,500')).toBeInTheDocument()
    const row = screen.getByRole('link', { name: '#7 Raksha Mask' }).closest('tr') as HTMLElement
    expect(within(row).getByText('Nimal Silva')).toBeInTheDocument()
    expect(within(row).getByText('Declined')).toBeInTheDocument()
    expect(within(row).getByText('Card declined (Visa •••• 0002) · 1 attempt')).toBeInTheDocument()
  })

  it('switches status through the tabs, with counts', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('tab', { name: 'Paid (1)' }))

    expect(fetchPayments).toHaveBeenLastCalledWith('PAID', '', 1)
  })
})
