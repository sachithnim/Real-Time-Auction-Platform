import { act, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { recordServerTime, resetServerClock } from '../../lib/serverClock'
import { Countdown } from '../Countdown'

afterEach(() => {
  resetServerClock()
  vi.useRealTimers()
})

describe('<Countdown>', () => {
  it('counts down on the server clock even when the local clock is wrong', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-03-01T19:00:00.000Z')) // local clock an hour behind
    recordServerTime('2030-03-01T20:00:00.000Z', Date.now(), Date.now())

    render(<Countdown target="2030-03-01T20:05:00.000Z" />)

    expect(screen.getByRole('timer')).toHaveTextContent('05:00')

    act(() => {
      vi.advanceTimersByTime(60_000)
    })

    expect(screen.getByRole('timer')).toHaveTextContent('04:00')
  })

  it('warns inside the anti-sniping window and shows the ended text afterwards', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-03-01T19:59:00.000Z'))

    render(<Countdown target="2030-03-01T20:00:00.000Z" antiSnipeSeconds={120} endedText="Auction ended" />)
    expect(screen.getByText('Bids now extend the auction')).toBeInTheDocument()

    act(() => {
      vi.advanceTimersByTime(61_000)
    })

    expect(screen.getByRole('timer')).toHaveTextContent('Auction ended')
  })
})
