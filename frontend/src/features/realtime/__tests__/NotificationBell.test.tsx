import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchInbox, markAllNoticesRead, markNoticeRead, type InboxNotice, type InboxPage } from '../inboxApi'
import { NotificationBell } from '../NotificationBell'

vi.mock('../inboxApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../inboxApi')>()),
  fetchInbox: vi.fn(),
  markNoticeRead: vi.fn(),
  markAllNoticesRead: vi.fn(),
}))

const outbid: InboxNotice = {
  id: 'a1',
  type: 'Outbid',
  data: { auction_id: 7, title: 'Brass Telescope', current_price: 15_500, min_next_bid: 16_000 },
  read: false,
  at: '2026-11-06T10:00:00Z',
}
const won: InboxNotice = { id: 'b2', type: 'AuctionWon', data: { auction_id: 9, title: 'Pocket Watch', final_price: 59_000 }, read: true, at: '2026-11-05T10:00:00Z' }

function inbox(data: InboxNotice[], unread: number): InboxPage {
  return { data, meta: { server_time: '2026-11-06T10:00:00Z', current_page: 1, last_page: 1, unread_count: unread } }
}

function renderBell() {
  const router = createMemoryRouter(
    [
      { path: '/', element: <NotificationBell /> },
      { path: '/auctions/:id', element: <p>Auction page</p> },
    ],
    { initialEntries: ['/'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  )
  return router
}

beforeEach(() => {
  vi.mocked(fetchInbox).mockReset().mockResolvedValue(inbox([outbid, won], 1))
  vi.mocked(markNoticeRead).mockReset().mockResolvedValue()
  vi.mocked(markAllNoticesRead).mockReset().mockResolvedValue()
})

describe('<NotificationBell>', () => {
  it('shows how many are unread', async () => {
    renderBell()

    expect(await screen.findByRole('button', { name: 'Notifications, 1 unread' })).toBeInTheDocument()
  })

  it('lists notices with the same words as the pop-ups, unread ones marked', async () => {
    const user = userEvent.setup()
    renderBell()

    await user.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }))
    const menu = screen.getByRole('menu', { name: 'Notifications' })

    expect(within(menu).getByRole('menuitem', { name: /^Unread: Outbid on Brass Telescope\. Price is now Rs\. 15,500\. Bid Rs\. 16,000 to lead again\. / })).toBeInTheDocument()
    expect(within(menu).getByRole('menuitem', { name: /^You won! Pocket Watch for Rs\. 59,000\./ })).toBeInTheDocument()
  })

  it('opening a notice marks it read and goes to the auction', async () => {
    const user = userEvent.setup()
    const router = renderBell()

    await user.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }))
    await user.click(screen.getByRole('menuitem', { name: /Outbid on Brass Telescope/ }))

    expect(markNoticeRead).toHaveBeenCalledWith('a1')
    expect(router.state.location.pathname).toBe('/auctions/7')
  })

  it('marks everything read at once', async () => {
    vi.mocked(fetchInbox).mockResolvedValueOnce(inbox([outbid, won], 1)).mockResolvedValue(inbox([{ ...outbid, read: true }, won], 0))
    const user = userEvent.setup()
    renderBell()

    await user.click(await screen.findByRole('button', { name: 'Notifications, 1 unread' }))
    await user.click(screen.getByRole('button', { name: 'Mark all as read' }))

    expect(markAllNoticesRead).toHaveBeenCalledOnce()
    expect(await screen.findByRole('button', { name: 'Notifications' })).toBeInTheDocument()
  })

  it('explains what will appear when there is nothing yet', async () => {
    vi.mocked(fetchInbox).mockResolvedValue(inbox([], 0))
    const user = userEvent.setup()
    renderBell()

    await user.click(await screen.findByRole('button', { name: 'Notifications' }))

    expect(screen.getByText(/Nothing yet\./)).toBeInTheDocument()
  })
})
