import { describe, expect, it } from 'vitest'
import { clampOffset, clampScale, MAX_SCALE, swipeDirection, zoomAround } from '../photoViewerMath'

const frame = { width: 800, height: 600 }

describe('photo viewer zoom', () => {
  it('stays between the fitted photo and the maximum zoom', () => {
    expect(clampScale(0.5)).toBe(1)
    expect(clampScale(9)).toBe(MAX_SCALE)
    expect(clampScale(2)).toBe(2)
  })

  it('never lets a zoomed photo be dragged past its edges', () => {
    // At 2x the photo is 1600 wide: it can move 400 either way and no further.
    expect(clampOffset({ x: 1000, y: -1000 }, 2, frame)).toEqual({ x: 400, y: -300 })
    expect(clampOffset({ x: 50, y: 20 }, 1, frame)).toEqual({ x: 0, y: 0 })
  })

  it('keeps the point under the pointer in place while zooming', () => {
    const point = { x: 200, y: 100 }
    const zoomed = zoomAround(point, { scale: 1, offset: { x: 0, y: 0 } }, 2, frame)

    expect(zoomed.scale).toBe(2)
    // A spot's screen position is offset + spot * scale: the one at (200, 100) is still there.
    expect(zoomed.offset.x + 200 * 2).toBe(200)
    expect(zoomed.offset.y + 100 * 2).toBe(100)
  })

  it('zooming all the way out centres the photo again', () => {
    expect(zoomAround({ x: 300, y: 200 }, { scale: 3, offset: { x: -150, y: 80 } }, 1, frame)).toEqual({ scale: 1, offset: { x: 0, y: 0 } })
  })
})

describe('swipeDirection', () => {
  it('turns a clear sideways drag into the next or previous photo', () => {
    expect(swipeDirection(-120, 10)).toBe(1)
    expect(swipeDirection(120, -10)).toBe(-1)
  })

  it('ignores short drags and mostly vertical ones', () => {
    expect(swipeDirection(-30, 0)).toBe(0)
    expect(swipeDirection(-80, 90)).toBe(0)
  })
})
