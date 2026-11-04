import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { User } from '../../../types/api'
import { AuthContext, meQueryKey, type AuthApi } from '../../auth/useAuth'
import { updatePassword, updateProfile } from '../accountApi'
import { AccountPage } from '../AccountPage'

vi.mock('../accountApi', () => ({ updateProfile: vi.fn(), updatePassword: vi.fn() }))

const asha: User = { id: 2, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER' }

function validationError(errors: Record<string, string[]>) {
  const config = { headers: new AxiosHeaders() }
  const data = { error: { code: 'VALIDATION_FAILED', message: 'Invalid', details: { errors } } }
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', config, null, { status: 422, statusText: '', data, headers: {}, config })
}

function renderPage(user: User = asha) {
  const queryClient = new QueryClient()
  render(
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <AuthContext.Provider value={{ user, isLoading: false } as AuthApi}>
          <MemoryRouter>
            <AccountPage />
          </MemoryRouter>
        </AuthContext.Provider>
      </ToastProvider>
    </QueryClientProvider>,
  )
  return queryClient
}

beforeEach(() => {
  vi.mocked(updateProfile).mockReset()
  vi.mocked(updatePassword).mockReset()
})

describe('<AccountPage> profile', () => {
  it('saves a new name without asking for the password', async () => {
    vi.mocked(updateProfile).mockResolvedValue({ ...asha, first_name: 'Ashani', name: 'Ashani Perera' })
    const user = userEvent.setup()
    const queryClient = renderPage()

    const firstName = screen.getByLabelText('First name')
    await user.clear(firstName)
    await user.type(firstName, 'Ashani')
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(updateProfile).toHaveBeenCalledWith({ first_name: 'Ashani', last_name: 'Perera', email: 'asha@example.com', current_password: undefined })
    expect(await screen.findByText('Profile saved')).toBeInTheDocument()
    expect(queryClient.getQueryData(meQueryKey)).toMatchObject({ first_name: 'Ashani' })
  })

  it('asks for the current password only when the email changes', async () => {
    const user = userEvent.setup()
    renderPage()
    // The password card always has one; the profile card adds a second for an email change.
    expect(screen.getAllByLabelText('Current password')).toHaveLength(1)

    const email = screen.getByLabelText('Email')
    await user.clear(email)
    await user.type(email, 'asha.new@example.com')

    expect(screen.getAllByLabelText('Current password')).toHaveLength(2)
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByText('Enter your current password to change your email.')).toBeInTheDocument()
    expect(updateProfile).not.toHaveBeenCalled()
  })

  it('shows the server’s answer on the field it is about', async () => {
    vi.mocked(updateProfile).mockRejectedValue(validationError({ email: ['The email has already been taken.'] }))
    const user = userEvent.setup()
    renderPage()

    const email = screen.getByLabelText('Email')
    await user.clear(email)
    await user.type(email, 'ravi@example.com')
    await user.type(screen.getAllByLabelText('Current password')[0], 'password')
    await user.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument()
  })

  it('cannot be saved until something changes', () => {
    renderPage()
    expect(screen.getByRole('button', { name: 'Save profile' })).toBeDisabled()
  })
})

describe('<AccountPage> password', () => {
  async function fill(user: ReturnType<typeof userEvent.setup>, current: string, next: string, confirm = next) {
    await user.type(screen.getByLabelText('Current password'), current)
    await user.type(screen.getByLabelText('New password'), next)
    await user.type(screen.getByLabelText('Confirm new password'), confirm)
    await user.click(screen.getByRole('button', { name: 'Change password' }))
  }

  it('changes the password, clears the form and says other devices were signed out', async () => {
    vi.mocked(updatePassword).mockResolvedValue()
    const user = userEvent.setup()
    renderPage()

    await fill(user, 'password', 'brand-new-pass')

    expect(updatePassword).toHaveBeenCalledWith({ current_password: 'password', password: 'brand-new-pass', password_confirmation: 'brand-new-pass' })
    expect(await screen.findByText('You stay logged in here. Other devices were signed out.')).toBeInTheDocument()
    expect(screen.getByLabelText('New password')).toHaveValue('')
  })

  it('checks length, match and difference before sending', async () => {
    const user = userEvent.setup()
    renderPage()

    await fill(user, 'password', 'password')
    expect(await screen.findByText('Choose a password different from your current one.')).toBeInTheDocument()

    await user.clear(screen.getByLabelText('New password'))
    await user.type(screen.getByLabelText('New password'), 'brand-new-pass')
    await user.click(screen.getByRole('button', { name: 'Change password' }))
    expect(await screen.findByText('Passwords do not match.')).toBeInTheDocument()

    expect(updatePassword).not.toHaveBeenCalled()
  })

  it('a wrong current password is shown on that field', async () => {
    vi.mocked(updatePassword).mockRejectedValue(validationError({ current_password: ['Your current password is not correct.'] }))
    const user = userEvent.setup()
    renderPage()

    await fill(user, 'wrong-one', 'brand-new-pass')

    expect(await screen.findByText('Your current password is not correct.')).toBeInTheDocument()
  })
})
