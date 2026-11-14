import { useEffect, useRef, useState, type MouseEvent, type PointerEvent } from 'react'
import { createPortal } from 'react-dom'
import type { AuctionImage } from '../../types/api'
import { clampOffset, clampScale, swipeDirection, TAP_SCALE, zoomAround, type Point } from './photoViewerMath'

interface View {
  scale: number
  offset: Point
}

const FITTED: View = { scale: 1, offset: { x: 0, y: 0 } }

// Fixed colours: the photo sits on near-black in both themes, so it is not remapped for dark mode.
const control = 'flex h-11 w-11 items-center justify-center rounded-full bg-[#ffffff]/10 text-[#ffffff] transition hover:bg-[#ffffff]/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#ffffff]'

/**
 * The photos full screen. Arrow keys, the buttons or a swipe move between them;
 * a double-click / double-tap, pinch, the mouse wheel or +/- zooms, and dragging
 * moves around a zoomed photo. Escape or × closes it and focus goes back to
 * where it was.
 */
export function PhotoViewer({
  images,
  index,
  title,
  onIndexChange,
  onClose,
}: {
  images: AuctionImage[]
  index: number
  title: string
  onIndexChange: (index: number) => void
  onClose: () => void
}) {
  const [view, setView] = useState<View>(FITTED)
  const [shown, setShown] = useState(index)
  const [gesturing, setGesturing] = useState(false)
  const dialog = useRef<HTMLDivElement>(null)
  const frame = useRef<HTMLDivElement>(null)
  const closeButton = useRef<HTMLButtonElement>(null)
  const viewRef = useRef(view)
  const pointers = useRef(new Map<number, Point>())
  const gesture = useRef<{ start: Point; startView: View; startDistance: number; startMid: Point; at: number; moved: boolean; pinched: boolean } | null>(null)
  const lastTap = useRef<{ at: number; point: Point } | null>(null)
  // Phones send a double-click after a double-tap too; the tap is already handled.
  const lastPointerType = useRef('mouse')
  const several = images.length > 1
  const image = images[index]

  useEffect(() => {
    viewRef.current = view
  }, [view])

  // A different photo starts fitted to the screen.
  if (shown !== index) {
    setShown(index)
    setView(FITTED)
  }

  const go = (step: number) => onIndexChange((index + step + images.length) % images.length)
  const frameSize = () => {
    const rect = frame.current?.getBoundingClientRect()
    return { width: rect?.width ?? 1, height: rect?.height ?? 1 }
  }
  // A point relative to the frame's centre, which is where zooming is measured from.
  const fromCentre = (clientX: number, clientY: number): Point => {
    const rect = frame.current?.getBoundingClientRect()
    return rect ? { x: clientX - (rect.left + rect.width / 2), y: clientY - (rect.top + rect.height / 2) } : { x: 0, y: 0 }
  }
  const zoomTo = (scale: number, at: Point = { x: 0, y: 0 }) => setView((current) => zoomAround(at, current, scale, frameSize()))
  const toggleZoom = (at: Point) => zoomTo(viewRef.current.scale > 1 ? 1 : TAP_SCALE, at)

  // Focus moves into the viewer, the page behind stops scrolling, and both come back on close.
  useEffect(() => {
    const before = document.activeElement as HTMLElement | null
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    closeButton.current?.focus()
    return () => {
      document.body.style.overflow = overflow
      before?.focus?.()
    }
  }, [])

  // The next and previous photos load in the background, so moving between them is instant.
  useEffect(() => {
    if (!several) {
      return
    }
    for (const step of [1, -1]) {
      new Image().src = images[(index + step + images.length) % images.length].url
    }
  }, [index, images, several])

  // The wheel (and a trackpad pinch) zooms around the pointer. It has to be a non-passive
  // listener to stop the page from scrolling instead.
  useEffect(() => {
    const element = frame.current
    if (!element) {
      return
    }
    const onWheel = (e: WheelEvent) => {
      e.preventDefault()
      const rect = element.getBoundingClientRect()
      const at = { x: e.clientX - (rect.left + rect.width / 2), y: e.clientY - (rect.top + rect.height / 2) }
      setView((current) => zoomAround(at, current, current.scale * Math.exp(-e.deltaY * 0.0015), { width: rect.width, height: rect.height }))
    }
    element.addEventListener('wheel', onWheel, { passive: false })
    return () => element.removeEventListener('wheel', onWheel)
  }, [])

  const onKeyDown = (e: globalThis.KeyboardEvent) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      onClose()
    } else if (several && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
      e.preventDefault()
      go(e.key === 'ArrowRight' ? 1 : -1)
    } else if (e.key === '+' || e.key === '=') {
      zoomTo(viewRef.current.scale * 1.5)
    } else if (e.key === '-') {
      zoomTo(viewRef.current.scale / 1.5)
    } else if (e.key === '0') {
      setView(FITTED)
    } else if (e.key === 'Tab') {
      // Keep keyboard focus inside the viewer while it is open.
      const focusable = Array.from(dialog.current?.querySelectorAll<HTMLElement>('button') ?? [])
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (!dialog.current?.contains(document.activeElement)) {
        // Focus left the buttons (e.g. after clicking the photo): bring it back in.
        e.preventDefault()
        first?.focus()
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last?.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first?.focus()
      }
    }
  }

  // Keys work wherever focus is while the viewer is open: clicking the photo moves focus
  // off the buttons, and Escape must still close it. The latest handler is always used.
  const keyHandler = useRef(onKeyDown)
  useEffect(() => {
    keyHandler.current = onKeyDown
  })
  useEffect(() => {
    const listener = (e: globalThis.KeyboardEvent) => keyHandler.current(e)
    document.addEventListener('keydown', listener)
    return () => document.removeEventListener('keydown', listener)
  }, [])

  const onPointerDown = (e: PointerEvent<HTMLDivElement>) => {
    lastPointerType.current = e.pointerType
    e.currentTarget.setPointerCapture?.(e.pointerId)
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    const [a, b] = [...pointers.current.values()]
    gesture.current = {
      start: { x: e.clientX, y: e.clientY },
      startView: viewRef.current,
      startDistance: b ? Math.hypot(b.x - a.x, b.y - a.y) : 0,
      startMid: b ? fromCentre((a.x + b.x) / 2, (a.y + b.y) / 2) : { x: 0, y: 0 },
      at: Date.now(),
      moved: Boolean(b),
      // Once two fingers were down, this gesture is a pinch to its end, never a swipe or a tap.
      pinched: Boolean(b) || Boolean(gesture.current?.pinched && pointers.current.size > 1),
    }
    setGesturing(true)
  }

  const onPointerMove = (e: PointerEvent<HTMLDivElement>) => {
    const g = gesture.current
    if (!g || !pointers.current.has(e.pointerId)) {
      return
    }
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    const [a, b] = [...pointers.current.values()]

    if (b && g.startDistance > 0) {
      // Pinch: the distance between the fingers sets the zoom, around the point between them.
      const scale = clampScale(g.startView.scale * (Math.hypot(b.x - a.x, b.y - a.y) / g.startDistance))
      setView(zoomAround(g.startMid, g.startView, scale, frameSize()))
      return
    }

    if (g.pinched) {
      return
    }

    const dx = e.clientX - g.start.x
    const dy = e.clientY - g.start.y
    if (Math.abs(dx) > 6 || Math.abs(dy) > 6) {
      g.moved = true
    }
    if (g.startView.scale > 1) {
      // Zoomed in: drag to look around the photo.
      setView({ scale: g.startView.scale, offset: clampOffset({ x: g.startView.offset.x + dx, y: g.startView.offset.y + dy }, g.startView.scale, frameSize()) })
    }
  }

  const onPointerUp = (e: PointerEvent<HTMLDivElement>) => {
    const g = gesture.current
    pointers.current.delete(e.pointerId)
    if (pointers.current.size > 0 || !g) {
      return
    }
    gesture.current = null
    setGesturing(false)

    // Not zoomed: a sideways swipe turns to the next or previous photo.
    if (g.pinched) {
      return
    }
    if (g.startView.scale === 1 && several && g.moved) {
      const direction = swipeDirection(e.clientX - g.start.x, e.clientY - g.start.y)
      if (direction !== 0) {
        go(direction)
        return
      }
    }

    // A quick second tap on a touch screen zooms in or out (a mouse uses double-click).
    if (e.pointerType === 'touch' && !g.moved && Date.now() - g.at < 250) {
      const point = fromCentre(e.clientX, e.clientY)
      const previous = lastTap.current
      if (previous && Date.now() - previous.at < 300 && Math.hypot(previous.point.x - point.x, previous.point.y - point.y) < 30) {
        lastTap.current = null
        toggleZoom(point)
      } else {
        lastTap.current = { at: Date.now(), point }
      }
    }
  }

  const onDoubleClick = (e: MouseEvent<HTMLDivElement>) => {
    if (lastPointerType.current !== 'touch') {
      toggleZoom(fromCentre(e.clientX, e.clientY))
    }
  }
  const zoomed = view.scale > 1

  return createPortal(
    <div
      ref={dialog}
      role="dialog"
      aria-modal="true"
      aria-label={`Photos of ${title}`}
      className="fixed inset-0 z-50 flex flex-col bg-[#020617] text-[#ffffff]"
    >
      <div className="flex items-center gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
        <p className="min-w-0 flex-1 truncate text-sm">
          <span className="font-semibold">{title}</span>
          {several && (
            <span className="ml-2 text-[#cbd5e1] tabular-nums" aria-live="polite">
              {index + 1} / {images.length}
            </span>
          )}
        </p>
        <button
          type="button"
          onClick={() => (zoomed ? setView(FITTED) : zoomTo(TAP_SCALE))}
          aria-label={zoomed ? 'Zoom out' : 'Zoom in'}
          aria-pressed={zoomed}
          className={control}
        >
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden>
            <circle cx="11" cy="11" r="7" />
            <path d={zoomed ? 'M8 11h6M20 20l-3.5-3.5' : 'M8 11h6M11 8v6M20 20l-3.5-3.5'} />
          </svg>
        </button>
        <button ref={closeButton} type="button" onClick={onClose} aria-label="Close photos" className={control}>
          <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" aria-hidden>
            <path d="M6 6l12 12M18 6L6 18" />
          </svg>
        </button>
      </div>

      <div className="relative min-h-0 flex-1">
        <div
          ref={frame}
          className={`flex h-full w-full touch-none items-center justify-center overflow-hidden select-none ${zoomed ? (gesturing ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'}`}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerCancel={onPointerUp}
          onDoubleClick={onDoubleClick}
        >
          <img
            key={image.id}
            src={image.url}
            alt={`${title}, photo ${index + 1} of ${images.length}`}
            width={image.width}
            height={image.height}
            draggable={false}
            className={`max-h-full max-w-full object-contain ${gesturing ? '' : 'motion-safe:transition-transform motion-safe:duration-200'}`}
            style={{ transform: `translate(${view.offset.x}px, ${view.offset.y}px) scale(${view.scale})` }}
          />
        </div>

        {several && (
          <>
            <button type="button" onClick={() => go(-1)} aria-label="Previous photo" className={`${control} absolute top-1/2 left-3 -translate-y-1/2`}>
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M15 18l-6-6 6-6" />
              </svg>
            </button>
            <button type="button" onClick={() => go(1)} aria-label="Next photo" className={`${control} absolute top-1/2 right-3 -translate-y-1/2`}>
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M9 18l6-6-6-6" />
              </svg>
            </button>
          </>
        )}
      </div>

      <p className="px-4 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-center text-xs text-[#94a3b8]">
        {/* Touch hints on phones, keyboard and mouse hints on larger screens. */}
        <span className="sm:hidden">{several ? 'Swipe for the next photo · ' : ''}Double-tap or pinch to zoom</span>
        <span className="hidden sm:inline">{several ? 'Arrow keys for the next photo · ' : ''}Double-click or scroll to zoom · Esc to close</span>
      </p>
    </div>,
    document.body,
  )
}
