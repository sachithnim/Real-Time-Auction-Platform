import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { User } from '../../../types/api'
import { AuthContext, type AuthApi } from '../../auth/useAuth'
import { fetchEmailPreferences, updateEmailPreferences, type EmailPreferences } from '../emailPreferences'
import { EmailSettingsPage } from '../EmailSettingsPage'
import { UnsubscribedPage } from '../UnsubscribedPage'

vi.mock('../emailPreferences', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../emailPreferences')>()),
  fetchEmailPreferences: vi.fn(),
  updateEmailPreferences: vi.fn(),
}))

const bidder: User = { id: 2, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER' }
const allOn: EmailPreferences = { OUTBID: true, MAX_REACHED: true, WON: true, REMINDERS: true, RECEIPTS: true }

function renderAt(path: string, user: User | null = bidder) {
  const router = createMemoryRouter(
    [
      { path: '/account/emails', element: <EmailSettingsPage /> },
      { path: '/unsubscribed', element: <UnsubscribedPage /> },
    ],
    { initialEntries: [path] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <AuthContext.Provider value={{ user, isLoading: false } as AuthApi}>
          <RouterProvider router={router} />
        </AuthContext.Provider>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.mocked(fetchEmailPreferences).mockReset().mockResolvedValue(allOn)
  vi.mocked(updateEmailPreferences).mockReset()
})

describe('<EmailSettingsPage>', () => {
  it('shows a switch for every email, on by default', async () => {
    renderAt('/account/emails')

    const switches = await screen.findAllByRole('switch')
    expect(switches).toHaveLength(5)
    switches.forEach((s) => expect(s).toHaveAttribute('aria-checked', 'true'))
    expect(screen.getByText('asha@example.com')).toBeInTheDocument()
  })

  it('turns one email off at once and saves only that change', async () => {
    vi.mocked(updateEmailPreferences).mockResolvedValue({ ...allOn, OUTBID: false })
    renderAt('/account/emails')

    const outbid = await screen.findByRole('switch', { name: 'Outbid' })
    await userEvent.click(outbid)

    expect(outbid).toHaveAttribute('aria-checked', 'false')
    expect(updateEmailPreferences).toHaveBeenCalledWith({ OUTBID: false })
    expect(await screen.findByText('Email settings saved')).toBeInTheDocument()
  })

  it('puts the switch back when saving fails', async () => {
    vi.mocked(updateEmailPreferences).mockRejectedValue(new Error('Network Error'))
    renderAt('/account/emails')

    const won = await screen.findByRole('switch', { name: 'You won' })
    await userEvent.click(won)

    expect(await screen.findByText('Not saved')).toBeInTheDocument()
    expect(won).toHaveAttribute('aria-checked', 'true')
  })

  it('can turn every email off in one go', async () => {
    vi.mocked(updateEmailPreferences).mockResolvedValue({ OUTBID: false, MAX_REACHED: false, WON: false, REMINDERS: false, RECEIPTS: false })
    renderAt('/account/emails')

    await userEvent.click(await screen.findByRole('button', { name: 'Turn all emails off' }))

    expect(updateEmailPreferences).toHaveBeenCalledWith({ OUTBID: false, MAX_REACHED: false, WON: false, REMINDERS: false, RECEIPTS: false })
    expect(await screen.findByRole('button', { name: 'Turn all emails on' })).toBeInTheDocument()
  })
})

describe('<UnsubscribedPage>', () => {
  it('names the email that was switched off', () => {
    renderAt('/unsubscribed?type=REMINDERS', null)

    expect(screen.getByText(/"Watchlist reminders" emails/)).toBeInTheDocument()
    // Not signed in: the settings link goes through the login page.
    expect(screen.getByRole('link', { name: 'Manage email settings' })).toHaveAttribute('href', '/login')
  })

  it('links a signed-in bidder straight to their settings', () => {
    renderAt('/unsubscribed?type=OUTBID')

    expect(screen.getByRole('link', { name: 'Manage email settings' })).toHaveAttribute('href', '/account/emails')
  })
})
