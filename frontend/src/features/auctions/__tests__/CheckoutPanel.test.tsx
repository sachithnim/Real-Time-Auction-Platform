import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { Auction, Payment } from '../../../types/api'
import { fetchMyPayment, payForAuction } from '../api'
import { CheckoutPanel } from '../CheckoutPanel'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchMyPayment: vi.fn(),
  payForAuction: vi.fn(),
}))

const auction = { id: 4, title: 'Kandyan Mask', status: 'COMPLETED', result: 'SOLD', is_winner: true } as Auction

const pending: Payment = {
  id: 1, auction_id: 4, amount: 15_500, status: 'PENDING', card_brand: null, card_last4: null,
  reference: null, failure_reason: null, attempts: 0, paid_at: null,
}

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <CheckoutPanel auction={auction} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

async function fillCard(number: string) {
  await userEvent.type(await screen.findByLabelText('Name on card'), 'Asha Perera')
  await userEvent.type(screen.getByLabelText('Card number'), number)
  await userEvent.type(screen.getByLabelText('Expiry (MM/YY)'), '12/39')
  await userEvent.type(screen.getByLabelText('CVC'), '123')
}

beforeEach(() => {
  vi.mocked(fetchMyPayment).mockReset()
  vi.mocked(payForAuction).mockReset()
})

describe('<CheckoutPanel>', () => {
  it('pays the amount due and shows the receipt', async () => {
    vi.mocked(fetchMyPayment).mockResolvedValue(pending)
    vi.mocked(payForAuction).mockResolvedValue({
      ...pending, status: 'PAID', card_brand: 'VISA', card_last4: '4242', reference: 'PAY-ABC1234567', attempts: 1, paid_at: '2026-10-27T12:00:00Z',
    })
    renderPanel()

    await fillCard('4242 4242 4242 4242')
    await userEvent.click(screen.getByRole('button', { name: 'Pay Rs. 15,500' }))

    expect(payForAuction).toHaveBeenCalledWith(
      4,
      { cardholder_name: 'Asha Perera', card_number: '4242 4242 4242 4242', expiry: '12/39', cvc: '123' },
      expect.any(String),
    )
    expect(await screen.findByText('Paid ✓')).toBeInTheDocument()
    expect(screen.getByText('Receipt PAY-ABC1234567')).toBeInTheDocument()
  })

  it('explains a declined card and lets the winner try again', async () => {
    vi.mocked(fetchMyPayment).mockResolvedValue(pending)
    vi.mocked(payForAuction).mockResolvedValue({ ...pending, status: 'FAILED', failure_reason: 'CARD_DECLINED', card_last4: '0002', attempts: 1 })
    renderPanel()

    await fillCard('4000 0000 0000 0002')
    await userEvent.click(screen.getByRole('button', { name: 'Pay Rs. 15,500' }))

    expect(await screen.findByText('Your card was declined. Try another card.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Pay Rs. 15,500' })).toBeEnabled()
  })

  it('does not send an invalid card number', async () => {
    vi.mocked(fetchMyPayment).mockResolvedValue(pending)
    renderPanel()

    await fillCard('4242 4242 4242 4241')
    await userEvent.click(screen.getByRole('button', { name: 'Pay Rs. 15,500' }))

    expect(await screen.findByText('This card number is not valid.')).toBeInTheDocument()
    expect(payForAuction).not.toHaveBeenCalled()
  })

  it('shows the receipt straight away when already paid', async () => {
    vi.mocked(fetchMyPayment).mockResolvedValue({ ...pending, status: 'PAID', card_brand: 'VISA', card_last4: '4242', reference: 'PAY-XYZ9876543' })
    renderPanel()

    expect(await screen.findByText('Receipt PAY-XYZ9876543')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /pay/i })).not.toBeInTheDocument()
  })
})
