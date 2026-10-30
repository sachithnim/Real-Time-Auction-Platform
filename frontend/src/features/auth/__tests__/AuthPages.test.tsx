import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../../../types/api'
import { LoginPage } from '../LoginPage'
import { RegisterPage } from '../RegisterPage'
import { AuthContext, type AuthApi } from '../useAuth'

const bidder: User = { id: 2, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER' }

function renderPage(path: '/login' | '/register') {
  const auth = {
    user: null,
    isLoading: false,
    login: vi.fn().mockResolvedValue(bidder),
    register: vi.fn().mockResolvedValue(bidder),
    logout: vi.fn(),
  } satisfies AuthApi
  const router = createMemoryRouter(
    [
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
      { path: '/', element: <p>Auction list</p> },
    ],
    { initialEntries: [path] },
  )
  render(
    <AuthContext.Provider value={auth}>
      <RouterProvider router={router} />
    </AuthContext.Provider>,
  )
  return auth
}

describe('<LoginPage>', () => {
  it('sends remember: false unless the box is ticked', async () => {
    const auth = renderPage('/login')
    await userEvent.type(screen.getByLabelText('Email'), 'asha@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'secret-pass')
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }))

    expect(auth.login).toHaveBeenCalledWith({ email: 'asha@example.com', password: 'secret-pass', remember: false })
    expect(await screen.findByText('Auction list')).toBeInTheDocument()
  })

  it('sends remember: true when "Remember me" is ticked', async () => {
    const auth = renderPage('/login')
    await userEvent.type(screen.getByLabelText('Email'), 'asha@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'secret-pass')
    await userEvent.click(screen.getByRole('checkbox', { name: /Remember me/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Log in' }))

    expect(auth.login).toHaveBeenCalledWith(expect.objectContaining({ remember: true }))
  })

  it('can show and hide the password', async () => {
    renderPage('/login')
    const input = screen.getByLabelText('Password')
    expect(input).toHaveAttribute('type', 'password')

    await userEvent.click(screen.getByRole('button', { name: 'Show password' }))
    expect(input).toHaveAttribute('type', 'text')
    await userEvent.click(screen.getByRole('button', { name: 'Hide password' }))
    expect(input).toHaveAttribute('type', 'password')
  })

  it('has the theme menu on the page', () => {
    renderPage('/login')
    expect(screen.getByRole('button', { name: /^Theme:/ })).toBeInTheDocument()
  })
})

describe('<RegisterPage>', () => {
  it('asks for first and last name separately and sends both', async () => {
    const auth = renderPage('/register')
    await userEvent.type(screen.getByLabelText('First name'), 'Asha')
    await userEvent.type(screen.getByLabelText('Last name'), 'Perera')
    await userEvent.type(screen.getByLabelText('Email'), 'asha@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'secret-pass')
    await userEvent.type(screen.getByLabelText('Confirm password'), 'secret-pass')
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }))

    expect(auth.register).toHaveBeenCalledWith({
      first_name: 'Asha',
      last_name: 'Perera',
      email: 'asha@example.com',
      password: 'secret-pass',
      password_confirmation: 'secret-pass',
    })
  })

  it('requires both names before sending anything', async () => {
    const auth = renderPage('/register')
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }))

    expect(await screen.findByText('Enter your first name.')).toBeInTheDocument()
    expect(screen.getByText('Enter your last name.')).toBeInTheDocument()
    expect(auth.register).not.toHaveBeenCalled()
  })

  it('shows a strength guide while typing the password', async () => {
    renderPage('/register')
    await userEvent.type(screen.getByLabelText('Password'), 'Abcdefghij1!')
    expect(screen.getByText('Strong')).toBeInTheDocument()
  })
})
