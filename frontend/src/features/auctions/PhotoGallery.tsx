import { useState, type KeyboardEvent } from 'react'
import type { AuctionImage } from '../../types/api'

const arrowButton =
  'absolute top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-[#ffffff]/90 text-[#0f172a] shadow ring-1 ring-black/10 transition hover:bg-[#ffffff]'

/**
 * The item's photos: a large view with previous / next, and thumbnails to jump.
 * Left and right arrow keys move through the photos when the gallery has focus.
 */
export function PhotoGallery({ images, title }: { images: AuctionImage[]; title: string }) {
  const [index, setIndex] = useState(0)

  if (images.length === 0) {
    return null
  }

  // A photo may be removed while the page is open (admins can edit before it goes live).
  const current = Math.min(index, images.length - 1)
  const image = images[current]
  const go = (next: number) => setIndex((next + images.length) % images.length)
  const onKeyDown = (e: KeyboardEvent) => {
    if (images.length > 1 && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
      e.preventDefault()
      go(current + (e.key === 'ArrowRight' ? 1 : -1))
    }
  }

  return (
    <section aria-roledescription="carousel" aria-label={`Photos of ${title}`} className="space-y-3">
      <div className="relative overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200" tabIndex={0} onKeyDown={onKeyDown}>
        <img
          src={image.url}
          alt={`${title}, photo ${current + 1} of ${images.length}`}
          width={image.width}
          height={image.height}
          className="aspect-[4/3] w-full object-contain"
        />
        {images.length > 1 && (
          <>
            <button type="button" onClick={() => go(current - 1)} aria-label="Previous photo" className={`${arrowButton} left-3`}>
              <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M15 18l-6-6 6-6" />
              </svg>
            </button>
            <button type="button" onClick={() => go(current + 1)} aria-label="Next photo" className={`${arrowButton} right-3`}>
              <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M9 18l6-6-6-6" />
              </svg>
            </button>
            <span className="absolute right-3 bottom-3 rounded-full bg-[#0f172a]/70 px-2 py-0.5 text-xs font-medium text-[#ffffff] tabular-nums" aria-live="polite">
              {current + 1} / {images.length}
            </span>
          </>
        )}
        <a
          href={image.url}
          target="_blank"
          rel="noreferrer"
          className="absolute top-3 right-3 rounded-full bg-[#ffffff]/90 px-2.5 py-1 text-xs font-medium text-[#0f172a] shadow ring-1 ring-black/10 hover:bg-[#ffffff]"
        >
          Full size
        </a>
      </div>

      {/* Padding keeps the selected ring inside the scrolling row, which would clip it. */}
      {images.length > 1 && (
        <div className="-m-1 flex gap-2 overflow-x-auto p-1">
          {images.map((thumb, i) => (
            <button
              key={thumb.id}
              type="button"
              onClick={() => setIndex(i)}
              aria-label={`Show photo ${i + 1}`}
              aria-current={i === current ? 'true' : undefined}
              className={`h-16 w-20 shrink-0 overflow-hidden rounded-md ring-2 transition ${i === current ? 'ring-indigo-600' : 'ring-transparent opacity-70 hover:opacity-100'}`}
            >
              <img src={thumb.url} alt="" loading="lazy" className="h-full w-full object-cover" />
            </button>
          ))}
        </div>
      )}
    </section>
  )
}
