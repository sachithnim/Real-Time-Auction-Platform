import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Pagination } from '../Pagination'

function renderPagination(props: Partial<Parameters<typeof Pagination>[0]> = {}) {
  const onChange = vi.fn()
  render(<Pagination page={2} lastPage={4} perPage={12} total={40} noun={['auction', 'auctions']} onChange={onChange} {...props} />)
  return onChange
}

describe('<Pagination>', () => {
  it('says which items are shown out of how many', () => {
    renderPagination()
    expect(screen.getByText(/Showing/)).toHaveTextContent('Showing 13–24 of 40 auctions')
  })

  it('marks the current page and moves to the one clicked', async () => {
    const onChange = renderPagination()

    expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page')
    await userEvent.click(screen.getByRole('button', { name: 'Page 4' }))
    expect(onChange).toHaveBeenCalledWith(4)
  })

  it('steps with the arrows and disables them at the ends', async () => {
    const onChange = renderPagination({ page: 1 })

    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Next page' }))
    expect(onChange).toHaveBeenCalledWith(2)
  })

  it('shows only the count when everything fits on one page', () => {
    renderPagination({ page: 1, lastPage: 1, total: 1 })

    expect(screen.getByText(/Showing/)).toHaveTextContent('Showing 1–1 of 1 auction')
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).toBeNull()
  })
})
