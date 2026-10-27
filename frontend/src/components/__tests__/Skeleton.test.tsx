import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'
import { NotFoundPage } from '../NotFoundPage'
import { AuctionCardsSkeleton, RowsSkeleton } from '../Skeleton'

describe('loading placeholders', () => {
  it('announce one loading status and hide the grey blocks from screen readers', () => {
    const { container } = render(<AuctionCardsSkeleton count={3} />)

    expect(screen.getByRole('status', { name: 'Loading auctions' })).toBeInTheDocument()
    const blocks = container.querySelectorAll('.bg-slate-200')
    expect(blocks.length).toBeGreaterThan(0)
    blocks.forEach((block) => expect(block).toHaveAttribute('aria-hidden'))
  })

  it('only announce rows that are loading on their own', () => {
    const { rerender } = render(<RowsSkeleton rows={2} />)
    expect(screen.queryByRole('status')).toBeNull()

    rerender(<RowsSkeleton rows={2} label="Loading bid history" />)
    expect(screen.getByRole('status', { name: 'Loading bid history' })).toBeInTheDocument()
  })
})

describe('<NotFoundPage>', () => {
  it('explains the problem and links back to the auctions', () => {
    render(
      <MemoryRouter>
        <NotFoundPage message="This auction does not exist." />
      </MemoryRouter>,
    )

    expect(screen.getByRole('heading', { name: 'This auction does not exist.' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to auctions' })).toHaveAttribute('href', '/')
  })
})
