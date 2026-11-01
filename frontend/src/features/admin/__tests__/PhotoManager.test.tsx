import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ToastProvider } from '../../../components/toast'
import type { AdminAuction, AuctionImage } from '../../../types/api'
import { deleteAuctionImage, reorderAuctionImages, uploadAuctionImages } from '../api'
import { PhotoManager } from '../PhotoManager'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  uploadAuctionImages: vi.fn(),
  deleteAuctionImage: vi.fn(),
  reorderAuctionImages: vi.fn(),
}))

const photo = (id: number): AuctionImage => ({ id, url: `https://api.test/auction-images/${id}.jpg`, width: 800, height: 600 })
const draft = { id: 4, status: 'DRAFT', images: [photo(1), photo(2)] } as AdminAuction

function renderManager(auction: AdminAuction = draft) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <PhotoManager auction={auction} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

const file = (name: string, type = 'image/jpeg') => new File(['x'], name, { type })

beforeEach(() => {
  vi.mocked(uploadAuctionImages).mockReset()
  vi.mocked(deleteAuctionImage).mockReset()
  vi.mocked(reorderAuctionImages).mockReset()
})

describe('<PhotoManager>', () => {
  it('lists the photos in order and marks the cover', () => {
    renderManager()

    expect(screen.getByRole('heading')).toHaveTextContent('Photos (2 of 8)')
    expect(screen.getByRole('img', { name: 'Photo 1' }).closest('li')).toHaveTextContent('Cover')
    expect(screen.getByRole('img', { name: 'Photo 2' }).closest('li')).not.toHaveTextContent('Cover')
  })

  it('uploads the photos that pass the checks and explains the rest', async () => {
    vi.mocked(uploadAuctionImages).mockResolvedValue([photo(1), photo(2), photo(3)])
    renderManager()

    await userEvent.upload(screen.getByLabelText(/Choose photos/), [file('front.jpg'), file('notes.pdf', 'application/pdf')], { applyAccept: false })

    expect(uploadAuctionImages).toHaveBeenCalledOnce()
    expect(vi.mocked(uploadAuctionImages).mock.calls[0][1].map((f) => f.name)).toEqual(['front.jpg'])
    expect(screen.getByText('notes.pdf: use a JPG, PNG or WebP photo.')).toBeInTheDocument()
    expect(await screen.findByText('Photo added')).toBeInTheDocument()
  })

  it('makes another photo the cover', async () => {
    vi.mocked(reorderAuctionImages).mockResolvedValue([photo(2), photo(1)])
    renderManager()

    await userEvent.click(screen.getByRole('button', { name: 'Make cover' }))

    expect(reorderAuctionImages).toHaveBeenCalledWith(4, [2, 1])
  })

  it('asks before removing a photo', async () => {
    vi.mocked(deleteAuctionImage).mockResolvedValue([photo(2)])
    renderManager()

    await userEvent.click(screen.getByRole('button', { name: 'Remove photo 1' }))
    expect(deleteAuctionImage).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Remove' }))
    await waitFor(() => expect(deleteAuctionImage).toHaveBeenCalledWith(4, 1))
  })

  it('is read-only once the auction is live', () => {
    renderManager({ ...draft, status: 'LIVE' })

    expect(screen.getByText('Photos are locked once the auction has started.')).toBeInTheDocument()
    expect(screen.queryByLabelText(/Choose photos/)).toBeNull()
    expect(screen.queryByRole('button', { name: /Remove photo/ })).toBeNull()
  })

  it('hides the upload area when the auction has eight photos', () => {
    renderManager({ ...draft, images: [1, 2, 3, 4, 5, 6, 7, 8].map(photo) })

    expect(screen.queryByLabelText(/Choose photos/)).toBeNull()
  })
})
