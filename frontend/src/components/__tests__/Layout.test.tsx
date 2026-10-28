import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import type { AuthApi } from '../../features/auth/useAuth'
import { AuthContext } from '../../features/auth/useAuth'
import type { User } from '../../types/api'
import { Layout } from '../Layout'
import { ToastProvider } from '../toast'

// No WebSocket in tests.
vi.mock('../../lib/echo', () => ({ getEcho: () => null, onConnectionChange: () => () => {} }))

const admin: User = { id: 1, name: 'Platform Admin', email: 'admin@auction.test', role: 'ADMIN' }
const bidder: User = { id: 2, name: 'Asha Perera', email: 'bidder1@auction.test', role: 'BIDDER' }

function renderLayout(user: User | null) {
  const auth = { user, isLoading: false, logout: vi.fn().mockResolvedValue(undefined) } as unknown as AuthApi
  const router = createMemoryRouter(
    [
      {
        element: <Layout />,
        children: [
          { path: '/', element: <p>Auction list</p> },
          { path: '/admin/monitor', element: <p>Monitor page</p> },
          { path: '/login', element: <p>Login page</p> },
        ],
      },
    ],
    { initialEntries: ['/'] },
  )

  render(
    <QueryClientProvider client={new QueryClient()}>
      <ToastProvider>
        <AuthContext.Provider value={auth}>
          <RouterProvider router={router} />
        </AuthContext.Provider>
      </ToastProvider>
    </QueryClientProvider>,
  )
  return auth
}

const menuButton = () => screen.getByRole('button', { name: /menu/i })
const phoneMenu = () => document.getElementById('mobile-menu')

describe('<Layout> skip link', () => {
  it('is the first thing a keyboard user reaches and jumps to the page content', async () => {
    renderLayout(bidder)

    await userEvent.tab()
    const skip = screen.getByRole('link', { name: 'Skip to content' })
    expect(skip).toHaveFocus()
    expect(skip).toHaveAttribute('href', '#main')
    expect(document.getElementById('main')).toHaveAttribute('tabindex', '-1')
  })
})

describe('<Layout> phone menu', () => {
  it('starts closed and opens with every admin link', async () => {
    renderLayout(admin)

    expect(phoneMenu()).toBeNull()
    expect(menuButton()).toHaveAttribute('aria-expanded', 'false')

    await userEvent.click(menuButton())

    expect(menuButton()).toHaveAttribute('aria-expanded', 'true')
    const menu = within(phoneMenu()!)
    for (const label of ['Auctions', 'Manage auctions', 'Live monitor', 'Categories', 'Flags', 'Statistics']) {
      expect(menu.getByRole('link', { name: label })).toBeInTheDocument()
    }
    expect(menu.getByText('Platform Admin')).toBeInTheDocument()
  })

  it('shows bidders only the public links', async () => {
    renderLayout(bidder)
    await userEvent.click(menuButton())

    const menu = within(phoneMenu()!)
    expect(menu.getByRole('link', { name: 'Auctions' })).toBeInTheDocument()
    expect(menu.queryByRole('link', { name: 'Manage auctions' })).toBeNull()
  })

  it('offers log in and register to guests', async () => {
    renderLayout(null)
    await userEvent.click(menuButton())

    const menu = within(phoneMenu()!)
    expect(menu.getByRole('link', { name: 'Log in' })).toBeInTheDocument()
    expect(menu.getByRole('link', { name: 'Register' })).toBeInTheDocument()
  })

  it('closes after choosing a page', async () => {
    renderLayout(admin)
    await userEvent.click(menuButton())
    await userEvent.click(within(phoneMenu()!).getByRole('link', { name: 'Live monitor' }))

    expect(await screen.findByText('Monitor page')).toBeInTheDocument()
    expect(phoneMenu()).toBeNull()
  })

  it('closes on Escape', async () => {
    renderLayout(admin)
    await userEvent.click(menuButton())
    await userEvent.keyboard('{Escape}')

    expect(phoneMenu()).toBeNull()
  })

  it('logs out from the menu and goes to the login page', async () => {
    const auth = renderLayout(bidder)
    await userEvent.click(menuButton())
    await userEvent.click(within(phoneMenu()!).getByRole('button', { name: 'Log out' }))

    expect(auth.logout).toHaveBeenCalledOnce()
    expect(await screen.findByText('Login page')).toBeInTheDocument()
    expect(phoneMenu()).toBeNull()
  })
})
