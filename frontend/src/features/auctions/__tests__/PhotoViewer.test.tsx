import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { AuctionImage } from '../../../types/api'
import { PhotoGallery } from '../PhotoGallery'

const images: AuctionImage[] = [1, 2, 3].map((id) => ({ id, url: `https://api.test/auction-images/${id}.jpg`, width: 800, height: 600 }))
const viewer = () => screen.getByRole('dialog', { name: 'Photos of Brass Telescope' })
const big = () => viewer().querySelector('img')!

async function openViewer() {
  render(<PhotoGallery images={images} title="Brass Telescope" />)
  await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))
}

describe('<PhotoViewer>', () => {
  it('opens full screen on the photo being shown, with focus on Close', async () => {
    render(<PhotoGallery images={images} title="Brass Telescope" />)
    await userEvent.click(screen.getByRole('button', { name: 'Show photo 2' }))
    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    expect(viewer()).toHaveAttribute('aria-modal', 'true')
    expect(big()).toHaveAccessibleName('Brass Telescope, photo 2 of 3')
    expect(viewer()).toHaveTextContent('2 / 3')
    expect(screen.getByRole('button', { name: 'Close photos' })).toHaveFocus()
    expect(document.body.style.overflow).toBe('hidden')
  })

  it('also opens from a click on the photo itself', async () => {
    render(<PhotoGallery images={images} title="Brass Telescope" />)
    await userEvent.click(screen.getByRole('img', { name: 'Brass Telescope, photo 1 of 3' }))

    expect(viewer()).toBeInTheDocument()
  })

  it('moves between photos with the arrow keys and buttons, and the gallery follows', async () => {
    await openViewer()

    fireEvent.keyDown(viewer(), { key: 'ArrowRight' })
    expect(big()).toHaveAccessibleName('Brass Telescope, photo 2 of 3')
    await userEvent.click(screen.getAllByRole('button', { name: 'Previous photo' }).at(-1)!)
    await userEvent.click(screen.getAllByRole('button', { name: 'Previous photo' }).at(-1)!)
    expect(big()).toHaveAccessibleName('Brass Telescope, photo 3 of 3')

    fireEvent.keyDown(viewer(), { key: 'Escape' })
    expect(screen.getByRole('button', { name: 'Show photo 3' })).toHaveAttribute('aria-current', 'true')
  })

  it('closes with Escape, gives the page its scrolling back and returns focus', async () => {
    await openViewer()
    const opener = screen.getByRole('button', { name: 'Full screen' })

    fireEvent.keyDown(viewer(), { key: 'Escape' })

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(document.body.style.overflow).toBe('')
    expect(opener).toHaveFocus()
  })

  it('still closes with Escape after a click on the photo moved focus away', async () => {
    await openViewer()
    ;(document.activeElement as HTMLElement).blur()
    expect(document.body).toHaveFocus()

    fireEvent.keyDown(document.body, { key: 'Escape' })

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('zooms in and out with the button and the keyboard', async () => {
    await openViewer()
    const zoom = screen.getByRole('button', { name: 'Zoom in' })

    await userEvent.click(zoom)
    expect(screen.getByRole('button', { name: 'Zoom out' })).toHaveAttribute('aria-pressed', 'true')
    expect(big().style.transform).toContain('scale(2.5)')

    fireEvent.keyDown(viewer(), { key: '0' })
    expect(big().style.transform).toContain('scale(1)')
    fireEvent.keyDown(viewer(), { key: '+' })
    expect(big().style.transform).toContain('scale(1.5)')
  })

  it('a sideways swipe turns the page, but not while zoomed in', async () => {
    await openViewer()
    const frame = big().parentElement!

    fireEvent.pointerDown(frame, { pointerId: 1, clientX: 300, clientY: 200 })
    fireEvent.pointerMove(frame, { pointerId: 1, clientX: 150, clientY: 210 })
    fireEvent.pointerUp(frame, { pointerId: 1, clientX: 150, clientY: 210 })
    expect(big()).toHaveAccessibleName('Brass Telescope, photo 2 of 3')

    fireEvent.keyDown(viewer(), { key: '+' })
    fireEvent.pointerDown(frame, { pointerId: 2, clientX: 300, clientY: 200 })
    fireEvent.pointerMove(frame, { pointerId: 2, clientX: 150, clientY: 210 })
    fireEvent.pointerUp(frame, { pointerId: 2, clientX: 150, clientY: 210 })
    expect(big()).toHaveAccessibleName('Brass Telescope, photo 2 of 3')
  })

  it('keeps Tab inside the viewer', async () => {
    await openViewer()
    const buttons = Array.from(viewer().querySelectorAll('button'))

    buttons.at(-1)!.focus()
    fireEvent.keyDown(viewer(), { key: 'Tab' })
    // jsdom does not move focus itself; the viewer's wrap-around does.
    expect(buttons[0]).toHaveFocus()
  })

  it('a single photo has no next or previous', async () => {
    render(<PhotoGallery images={[images[0]]} title="Brass Telescope" />)
    await userEvent.click(screen.getByRole('button', { name: 'Full screen' }))

    expect(viewer()).not.toHaveTextContent('1 / 1')
    expect(screen.queryByRole('button', { name: 'Next photo' })).not.toBeInTheDocument()
  })
})
