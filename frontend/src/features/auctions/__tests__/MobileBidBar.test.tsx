import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Auction, User } from '../../../types/api'
import { MobileBidBar } from '../MobileBidBar'

const bidder: User = { id: 5, first_name: 'Asha', last_name: 'Perera', name: 'Asha Perera', email: 'asha@example.com', role: 'BIDDER', email_verified: true }
const live = {
  id: 3, status: 'LIVE', starting_price: 20_000, current_price: 25_000, min_next_bid: 25_500, is_leading: false, my_pseudonym: null,
  end_at: '2099-01-01T00:00:00Z', anti_snipe_seconds: 120,
} as unknown as Auction

// A stand-in IntersectionObserver the test can drive: is the bid card on screen?
let report: (visible: boolean) => void
beforeEach(() => {
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      constructor(callback: (entries: { isIntersecting: boolean }[]) => void) {
        report = (visible) => callback([{ isIntersecting: visible }])
      }
      observe() {}
      disconnect() {}
    },
  )
})
afterEach(() => vi.unstubAllGlobals())

function renderBar(auction: Auction = live) {
  const card = createRef<HTMLDivElement>()
  render(
    <MemoryRouter>
      <div ref={card}>
        <input name="amount" aria-label="Your bid" />
      </div>
      <MobileBidBar auction={auction} user={bidder} bidCard={card} />
    </MemoryRouter>,
  )
  return card
}

describe('<MobileBidBar>', () => {
  it('stays hidden while the bid card is on screen and appears once it scrolls away', () => {
    renderBar()
    act(() => report(true))
    expect(screen.queryByRole('region', { name: 'Bidding' })).not.toBeInTheDocument()

    act(() => report(false))
    expect(screen.getByRole('region', { name: 'Bidding' })).toHaveTextContent('Rs. 25,000')
    expect(screen.getByRole('timer')).toBeInTheDocument()
  })

  it('brings the bid card back with the amount ready to type', async () => {
    const card = renderBar()
    const scrollIntoView = vi.fn()
    card.current!.scrollIntoView = scrollIntoView
    act(() => report(false))

    await userEvent.click(screen.getByRole('button', { name: 'Bid Rs. 25,500' }))
    await act(() => new Promise((resolve) => setTimeout(resolve, 450)))

    expect(scrollIntoView).toHaveBeenCalled()
    expect(screen.getByLabelText('Your bid')).toHaveFocus()
  })

  it('is not shown once the auction is no longer live', () => {
    renderBar({ ...live, status: 'COMPLETED' } as Auction)
    act(() => report(false))

    expect(screen.queryByRole('region', { name: 'Bidding' })).not.toBeInTheDocument()
  })
})
