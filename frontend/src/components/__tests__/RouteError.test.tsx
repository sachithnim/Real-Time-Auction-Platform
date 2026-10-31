import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { describe, expect, it, vi } from 'vitest'
import { RouteError } from '../RouteError'

function Crash(): never {
  throw new Error('boom')
}

describe('<RouteError>', () => {
  it('replaces a crashed page with a friendly message and a way out', () => {
    // React logs the thrown error; keep the test output clean.
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const router = createMemoryRouter([{ path: '/', element: <Crash />, errorElement: <RouteError /> }])
    render(<RouterProvider router={router} />)

    expect(screen.getByRole('heading', { name: 'Something went wrong on this page' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Go to auctions' })).toHaveAttribute('href', '/')
    expect(screen.queryByText('boom')).toBeNull()
  })
})
