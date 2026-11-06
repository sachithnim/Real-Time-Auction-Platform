import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../../../types/api'
import { EmailVerifiedPage } from '../EmailVerifiedPage'
import { AuthContext, type AuthApi } from '../useAuth'
import { resendVerification } from '../verification'
import { VerifyEmailBanner } from '../VerifyEmailBanner'

vi.mock('../verification', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../verification')>()),
  resendVerification: vi.fn(),
}))

const unverified: User = { id: 5, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER', email_verified: false }

function renderWith(ui: React.ReactNode, path = '/', user: User | null = unverified) {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <AuthContext.Provider value={{ user, isLoading: false } as AuthApi}>
        <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
      </AuthContext.Provider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(resendVerification).mockReset()
})

describe('<VerifyEmailBanner>', () => {
  it('says where the link went and sends it again', async () => {
    vi.mocked(resendVerification).mockResolvedValue()
    renderWith(<VerifyEmailBanner user={unverified} />)

    expect(screen.getByText(/We sent a link to asha@example.com/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Send the link again' }))

    expect(resendVerification).toHaveBeenCalledOnce()
    expect(await screen.findByText('Sent. Check your inbox.')).toBeInTheDocument()
  })

  it('explains the rate limit', async () => {
    const config = { headers: new AxiosHeaders() }
    vi.mocked(resendVerification).mockRejectedValue(new AxiosError('Too many', 'ERR', config, null, { status: 429, statusText: '', headers: {}, config, data: {} }))
    renderWith(<VerifyEmailBanner user={unverified} />)

    await userEvent.click(screen.getByRole('button', { name: 'Send the link again' }))

    expect(await screen.findByRole('button', { name: 'Too many tries. Wait a minute' })).toBeInTheDocument()
  })
})

describe('<EmailVerifiedPage>', () => {
  it('celebrates a confirmed email', () => {
    renderWith(<EmailVerifiedPage />, '/email-verified?status=verified')

    expect(screen.getByRole('heading', { name: 'Email confirmed' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Browse live auctions' })).toHaveAttribute('href', '/?status=LIVE')
  })

  it('an expired link offers a new one to a signed-in, unconfirmed bidder', () => {
    renderWith(<EmailVerifiedPage />, '/email-verified?status=invalid')

    expect(screen.getByRole('heading', { name: 'This link has expired' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Send the link again' })).toBeInTheDocument()
  })

  it('an expired link asks a visitor to log in first', () => {
    renderWith(<EmailVerifiedPage />, '/email-verified?status=nonsense', null)

    expect(screen.getByRole('heading', { name: 'This link has expired' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Log in to get a new link' })).toBeInTheDocument()
  })
})
