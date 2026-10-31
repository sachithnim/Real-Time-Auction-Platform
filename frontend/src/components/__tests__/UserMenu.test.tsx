import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import type { User } from '../../types/api'
import { initials } from '../../lib/initials'
import { UserMenu } from '../UserMenu'

const bidder: User = { id: 2, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER' }
const admin: User = { id: 1, first_name: 'Platform', last_name: 'Admin', name: 'Platform Admin', email: 'admin@auction.test', role: 'ADMIN' }

function renderMenu(user: User) {
  const onLogout = vi.fn()
  render(
    <MemoryRouter>
      <UserMenu user={user} onLogout={onLogout} />
    </MemoryRouter>,
  )
  return onLogout
}

describe('initials', () => {
  it('uses the first letters of the first and last name', () => {
    expect(initials(bidder)).toBe('AP')
  })

  it('falls back to the full name when the parts are missing', () => {
    expect(initials({ first_name: null, last_name: null, name: 'nimal  silva  perera' })).toBe('NS')
    expect(initials({ first_name: 'Madonna', last_name: null, name: 'Madonna' })).toBe('M')
  })
})

describe('<UserMenu>', () => {
  it('shows who is signed in and their role', async () => {
    renderMenu(bidder)
    await userEvent.click(screen.getByRole('button', { name: 'Account: Asha Perera' }))

    const menu = screen.getByRole('menu', { name: 'Account' })
    expect(menu).toHaveTextContent('asha@example.com')
    expect(menu).toHaveTextContent('Bidder')
  })

  it('links bidders to their watchlist; admins do not get it', async () => {
    renderMenu(bidder)
    await userEvent.click(screen.getByRole('button', { name: /Account/ }))
    expect(screen.getByRole('menuitem', { name: 'My watchlist' })).toHaveAttribute('href', '/?status=watching')
  })

  it('has no watchlist for admins', async () => {
    renderMenu(admin)
    await userEvent.click(screen.getByRole('button', { name: /Account/ }))
    expect(screen.queryByRole('menuitem', { name: 'My watchlist' })).toBeNull()
    expect(screen.getByRole('menu')).toHaveTextContent('Admin')
  })

  it('logs out and closes', async () => {
    const onLogout = renderMenu(bidder)
    await userEvent.click(screen.getByRole('button', { name: /Account/ }))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Log out' }))

    expect(onLogout).toHaveBeenCalledOnce()
    expect(screen.queryByRole('menu')).toBeNull()
  })

  it('closes on Escape and returns focus to the button', async () => {
    renderMenu(bidder)
    const button = screen.getByRole('button', { name: /Account/ })
    await userEvent.click(button)
    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('menu')).toBeNull()
    expect(button).toHaveFocus()
  })
})
