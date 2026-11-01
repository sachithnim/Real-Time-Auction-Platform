import type { AuctionImage } from '../types/api'

/**
 * An auction's cover photo, cropped to fill a fixed shape so a grid of cards lines up.
 * Without photos it shows a quiet placeholder of the same size.
 */
export function CoverPhoto({ image, className = '' }: { image: AuctionImage | undefined; className?: string }) {
  if (!image) {
    return (
      <div className={`flex items-center justify-center bg-linear-to-br from-slate-100 to-slate-200 text-slate-400 ${className}`} aria-hidden>
        <svg viewBox="0 0 24 24" className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="4" width="18" height="16" rx="2" />
          <circle cx="9" cy="10" r="2" />
          <path d="M21 16l-5-5-9 9" />
        </svg>
      </div>
    )
  }

  // Decorative here: the card's title already names the item.
  return <img src={image.url} alt="" loading="lazy" decoding="async" width={image.width} height={image.height} className={`object-cover ${className}`} />
}
