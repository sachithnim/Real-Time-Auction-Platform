import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { AdminAuction } from '../../../types/api'
import { createAuction, fetchAdminCategories, uploadAuctionImages } from '../api'
import { AdminAuctionFormPage } from '../AdminAuctionFormPage'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  createAuction: vi.fn(),
  uploadAuctionImages: vi.fn(),
  fetchAdminCategories: vi.fn(),
}))

// jsdom has no object URLs; the picker only needs a unique address per preview.
const realCreate = URL.createObjectURL
const realRevoke = URL.revokeObjectURL
let next = 0
beforeAll(() => {
  URL.createObjectURL = () => `blob:preview-${++next}`
  URL.revokeObjectURL = () => {}
})
afterAll(() => {
  URL.createObjectURL = realCreate
  URL.revokeObjectURL = realRevoke
})

beforeEach(() => {
  vi.mocked(createAuction).mockReset().mockResolvedValue({ id: 42, images: [] } as unknown as AdminAuction)
  vi.mocked(uploadAuctionImages).mockReset()
  vi.mocked(fetchAdminCategories).mockReset().mockResolvedValue([])
})

function renderNewForm() {
  const router = createMemoryRouter(
    [
      { path: '/admin/auctions/new', element: <AdminAuctionFormPage /> },
      { path: '/admin/auctions/:id', element: <p>Auction page</p> },
    ],
    { initialEntries: ['/admin/auctions/new'] },
  )
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

async function fillValidAuction() {
  await userEvent.type(screen.getByLabelText('Title'), 'Brass Telescope')
  await userEvent.type(screen.getByLabelText('Description'), 'Hand-polished, with leather grip.')
  await userEvent.type(screen.getByLabelText('Starting price (Rs.)'), '15000')
  fireEvent.change(screen.getByLabelText('Starts (your local time)'), { target: { value: '2031-01-01T10:00' } })
  fireEvent.change(screen.getByLabelText('Ends (your local time)'), { target: { value: '2031-01-01T12:00' } })
}

const photo = (name: string) => new File(['x'], name, { type: 'image/jpeg' })

describe('<AdminAuctionFormPage> photos on a new auction', () => {
  it('lets the admin choose photos and pick the cover before saving', async () => {
    renderNewForm()
    await userEvent.upload(await screen.findByLabelText(/Choose photos/), [photo('front.jpg'), photo('back.jpg')])

    expect(screen.getByRole('img', { name: 'Chosen photo 1: front.jpg' }).closest('li')).toHaveTextContent('Cover')
    await userEvent.click(screen.getByRole('button', { name: 'Make cover' }))
    expect(screen.getByRole('img', { name: 'Chosen photo 1: back.jpg' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Remove chosen photo 2' }))
    expect(screen.queryByRole('img', { name: /front\.jpg/ })).toBeNull()
  })

  it('creates the auction first, then uploads the photos in the chosen order', async () => {
    vi.mocked(uploadAuctionImages).mockResolvedValue([])
    renderNewForm()
    await fillValidAuction()
    await userEvent.upload(screen.getByLabelText(/Choose photos/), [photo('front.jpg'), photo('back.jpg')])
    await userEvent.click(screen.getByRole('button', { name: 'Make cover' }))

    await userEvent.click(screen.getByRole('button', { name: 'Save as draft' }))

    expect(await screen.findByText('Auction page')).toBeInTheDocument()
    expect(createAuction).toHaveBeenCalledOnce()
    expect(vi.mocked(uploadAuctionImages).mock.calls[0][0]).toBe(42)
    expect(vi.mocked(uploadAuctionImages).mock.calls[0][1].map((f) => f.name)).toEqual(['back.jpg', 'front.jpg'])
  })

  it('keeps the saved auction when the photos fail to upload, and says so', async () => {
    vi.mocked(uploadAuctionImages).mockRejectedValue(new Error('Network Error'))
    renderNewForm()
    await fillValidAuction()
    await userEvent.upload(screen.getByLabelText(/Choose photos/), [photo('front.jpg')])

    await userEvent.click(screen.getByRole('button', { name: 'Save as draft' }))

    expect(await screen.findByText('Auction page')).toBeInTheDocument()
    expect(screen.getByText('Auction saved, but the photos did not upload')).toBeInTheDocument()
  })

  it('does not upload anything when no photos were chosen', async () => {
    renderNewForm()
    await fillValidAuction()
    await userEvent.click(screen.getByRole('button', { name: 'Save as draft' }))

    expect(await screen.findByText('Auction page')).toBeInTheDocument()
    expect(uploadAuctionImages).not.toHaveBeenCalled()
  })
})
