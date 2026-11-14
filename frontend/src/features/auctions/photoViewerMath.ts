/** How far in the viewer zooms: 1 is the whole photo fitted to the screen. */
export const MIN_SCALE = 1
export const MAX_SCALE = 4
/** One double-click or double-tap goes straight to this. */
export const TAP_SCALE = 2.5
/** A horizontal drag at least this long (px) on an unzoomed photo turns the page. */
export const SWIPE_PX = 50

export interface Point {
  x: number
  y: number
}

export interface Size {
  width: number
  height: number
}

export function clampScale(scale: number): number {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale))
}

/**
 * Keeps a zoomed photo covering the frame: it can be moved until its edge reaches
 * the frame's edge, never further, so there is no empty gap to drag into.
 */
export function clampOffset(offset: Point, scale: number, frame: Size): Point {
  const maxX = ((scale - 1) * frame.width) / 2
  const maxY = ((scale - 1) * frame.height) / 2
  return {
    x: Math.min(maxX, Math.max(-maxX, offset.x)),
    y: Math.min(maxY, Math.max(-maxY, offset.y)),
  }
}

/**
 * Zooms around a point (relative to the frame's centre), so what is under the
 * pointer or fingers stays under them, as in any photo app.
 */
export function zoomAround(point: Point, from: { scale: number; offset: Point }, toScale: number, frame: Size): { scale: number; offset: Point } {
  const scale = clampScale(toScale)
  const ratio = scale / from.scale
  const offset = {
    x: point.x - (point.x - from.offset.x) * ratio,
    y: point.y - (point.y - from.offset.y) * ratio,
  }
  return { scale, offset: scale === 1 ? { x: 0, y: 0 } : clampOffset(offset, scale, frame) }
}

/** -1 for the previous photo, 1 for the next, 0 when the drag was not a clear sideways swipe. */
export function swipeDirection(dx: number, dy: number): -1 | 0 | 1 {
  if (Math.abs(dx) < SWIPE_PX || Math.abs(dx) < Math.abs(dy) * 1.5) {
    return 0
  }
  // Dragging the photo to the left brings in the next one.
  return dx < 0 ? 1 : -1
}
