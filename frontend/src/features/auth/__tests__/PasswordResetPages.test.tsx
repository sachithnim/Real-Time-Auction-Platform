import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ForgotPasswordPage } from '../ForgotPasswordPage'
import { LoginPage } from '../LoginPage'
import { requestPasswordReset, resetPassword } from '../passwordApi'
import { ResetPasswordPage } from '../ResetPasswordPage'
import { AuthContext, type AuthApi } from '../useAuth'

vi.mock('../passwordApi', () => ({ requestPasswordReset: vi.fn(), resetPassword: vi.fn() }))

function apiError(status: number, body: unknown) {
  const config = { headers: new AxiosHeaders() }
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, { status, statusText: '', data: body, headers: {}, config })
}

function renderAt(path: string) {
  const router = createMemoryRouter(
    [
      { path: '/login', element: <LoginPage /> },
      { path: '/forgot-password', element: <ForgotPasswordPage /> },
      { path: '/reset-password', element: <ResetPasswordPage /> },
    ],
    { initialEntries: [path] },
  )
  render(
    <AuthContext.Provider value={{ user: null, isLoading: false, login: vi.fn(), register: vi.fn(), logout: vi.fn() } satisfies AuthApi}>
      <RouterProvider router={router} />
    </AuthContext.Provider>,
  )
  return router
}

beforeEach(() => {
  vi.mocked(requestPasswordReset).mockReset()
  vi.mocked(resetPassword).mockReset()
})

describe('Forgot password', () => {
  it('is linked from the login page', async () => {
    const user = userEvent.setup()
    const router = renderAt('/login')

    await user.click(screen.getByRole('link', { name: 'Forgot password?' }))

    expect(router.state.location.pathname).toBe('/forgot-password')
  })

  it('sends the link and says so without confirming the account exists', async () => {
    vi.mocked(requestPasswordReset).mockResolvedValue()
    const user = userEvent.setup()
    renderAt('/forgot-password')

    await user.type(screen.getByLabelText('Email'), 'asha@example.com')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(requestPasswordReset).toHaveBeenCalledWith('asha@example.com')
    expect(await screen.findByRole('heading', { name: 'Check your email' })).toBeInTheDocument()
    expect(screen.getByText(/If/)).toHaveTextContent('If asha@example.com has a LiveBid account, we have sent it a link')
  })

  it('checks the email before sending', async () => {
    const user = userEvent.setup()
    renderAt('/forgot-password')

    await user.type(screen.getByLabelText('Email'), 'not-an-email')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
    expect(requestPasswordReset).not.toHaveBeenCalled()
  })

  it('explains the rate limit', async () => {
    vi.mocked(requestPasswordReset).mockRejectedValue(apiError(429, { error: { code: 'TOO_MANY_REQUESTS', message: 'Too many', details: {} } }))
    const user = userEvent.setup()
    renderAt('/forgot-password')

    await user.type(screen.getByLabelText('Email'), 'asha@example.com')
    await user.click(screen.getByRole('button', { name: 'Send reset link' }))

    expect(await screen.findByText('Too many requests. Wait a minute, then try again.')).toBeInTheDocument()
  })
})

describe('Reset password', () => {
  const link = '/reset-password?token=abc123&email=asha%40example.com'

  it('sets the new password and goes to log in with the email filled in', async () => {
    vi.mocked(resetPassword).mockResolvedValue()
    const user = userEvent.setup()
    const router = renderAt(link)

    expect(screen.getByText('For asha@example.com. You’ll be signed out everywhere else.')).toBeInTheDocument()
    await user.type(screen.getByLabelText('New password'), 'brand-new-pass')
    await user.type(screen.getByLabelText('Confirm new password'), 'brand-new-pass')
    await user.click(screen.getByRole('button', { name: 'Save new password' }))

    expect(resetPassword).toHaveBeenCalledWith({ token: 'abc123', email: 'asha@example.com', password: 'brand-new-pass', password_confirmation: 'brand-new-pass' })
    expect(await screen.findByText('Your password was changed. Log in with the new one.')).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/login')
    expect(screen.getByLabelText('Email')).toHaveValue('asha@example.com')
  })

  it('checks the passwords match before sending', async () => {
    const user = userEvent.setup()
    renderAt(link)

    await user.type(screen.getByLabelText('New password'), 'brand-new-pass')
    await user.type(screen.getByLabelText('Confirm new password'), 'something-else')
    await user.click(screen.getByRole('button', { name: 'Save new password' }))

    expect(await screen.findByText('Passwords do not match.')).toBeInTheDocument()
    expect(resetPassword).not.toHaveBeenCalled()
  })

  it('an expired link offers a new one', async () => {
    vi.mocked(resetPassword).mockRejectedValue(
      apiError(422, { error: { code: 'VALIDATION_FAILED', message: 'Invalid', details: { errors: { token: ['This reset link is invalid or has expired.'] } } } }),
    )
    const user = userEvent.setup()
    renderAt(link)

    await user.type(screen.getByLabelText('New password'), 'brand-new-pass')
    await user.type(screen.getByLabelText('Confirm new password'), 'brand-new-pass')
    await user.click(screen.getByRole('button', { name: 'Save new password' }))

    expect(await screen.findByRole('heading', { name: 'This link has expired' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Send a new link' })).toHaveAttribute('href', '/forgot-password')
  })

  it('a link without its token is treated as expired', () => {
    renderAt('/reset-password?email=asha%40example.com')

    expect(screen.getByRole('heading', { name: 'This link has expired' })).toBeInTheDocument()
  })
})
