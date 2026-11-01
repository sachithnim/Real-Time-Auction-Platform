import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { AuctionImage } from '../../../types/api'
import { PhotoGallery } from '../PhotoGallery'

const images: AuctionImage[] = [1, 2, 3].map((id) => ({ id, url: `https://api.test/auction-images/${id}.jpg`, width: 800, height: 600 }))
const shown = () => screen.getByRole('img', { name: /photo \d of 3/ })

describe('<PhotoGallery>', () => {
  it('shows nothing when the auction has no photos', () => {
    const { container } = render(<PhotoGallery images={[]} title="Brass Telescope" />)
    expect(container).toBeEmptyDOMElement()
  })

  it('starts on the cover and steps through the photos, wrapping around', async () => {
    render(<PhotoGallery images={images} title="Brass Telescope" />)
    expect(shown()).toHaveAccessibleName('Brass Telescope, photo 1 of 3')

    await userEvent.click(screen.getByRole('button', { name: 'Next photo' }))
    expect(shown()).toHaveAttribute('src', images[1].url)

    await userEvent.click(screen.getByRole('button', { name: 'Previous photo' }))
    await userEvent.click(screen.getByRole('button', { name: 'Previous photo' }))
    expect(shown()).toHaveAccessibleName('Brass Telescope, photo 3 of 3')
  })

  it('jumps to a photo from its thumbnail and moves with the arrow keys', async () => {
    render(<PhotoGallery images={images} title="Brass Telescope" />)

    await userEvent.click(screen.getByRole('button', { name: 'Show photo 3' }))
    expect(screen.getByRole('button', { name: 'Show photo 3' })).toHaveAttribute('aria-current', 'true')

    fireEvent.keyDown(shown().parentElement!, { key: 'ArrowRight' })
    expect(shown()).toHaveAccessibleName('Brass Telescope, photo 1 of 3')
  })

  it('has no controls for a single photo', () => {
    render(<PhotoGallery images={[images[0]]} title="Brass Telescope" />)

    expect(screen.queryByRole('button', { name: 'Next photo' })).toBeNull()
    expect(screen.getByRole('link', { name: 'Full size' })).toHaveAttribute('href', images[0].url)
  })
})
