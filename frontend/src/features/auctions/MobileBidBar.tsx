import { useEffect, useState, type RefObject } from 'react'
import { Link } from 'react-router'
import { Countdown } from '../../components/Countdown'
import { formatRs } from '../../lib/money'
import type { Auction, User } from '../../types/api'
import { mobileBidAction } from './mobileBidAction'

/**
 * Phones only: once the bid card has scrolled away (reading the history, the
 * description), the price, the time left and a bid button stay pinned at the
 * bottom. The button brings the bid card back with the amount ready to type.
 */
export function MobileBidBar({ auction, user, bidCard }: { auction: Auction; user: User | null; bidCard: RefObject<HTMLElement | null> }) {
  const cardVisible = useInView(bidCard)
  const action = mobileBidAction(auction, user)

  if (auction.status !== 'LIVE' || cardVisible) {
    return null
  }

  const backToBidCard = () => {
    const card = bidCard.current
    if (!card) {
      return
    }
    const smooth = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    card.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' })
    // Wait for the scroll before focusing, or the browser jumps instead of gliding.
    window.setTimeout(() => card.querySelector<HTMLInputElement>('input[name="amount"], input[name="max_amount"]')?.focus({ preventScroll: true }), smooth ? 400 : 0)
  }

  return (
    <>
      {/* Room at the end of the page, so the bar never hides the last lines. */}
      <div className="h-32 lg:hidden" aria-hidden />
      <div
        role="region"
        aria-label="Bidding"
        className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 pt-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(15,23,42,0.08)] backdrop-blur lg:hidden"
      >
        {/* On the narrowest phones the button takes its own row, so nothing overlaps. */}
        <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5 text-xs whitespace-nowrap text-slate-500">
              {auction.current_price === null ? 'Starting price' : 'Current price'}
              {action.status && (
                <span className={`rounded-full px-1.5 py-px text-[11px] font-semibold ${action.status === 'leading' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}>
                  {action.status === 'leading' ? 'Leading' : 'Outbid'}
                </span>
              )}
            </div>
            <div className="text-lg font-bold whitespace-nowrap tabular-nums">{formatRs(auction.current_price ?? auction.starting_price)}</div>
          </div>
          <div className="shrink-0 text-right whitespace-nowrap">
            <div className="text-xs text-slate-500">Ends in</div>
            <Countdown target={auction.end_at} antiSnipeSeconds={auction.anti_snipe_seconds} endedText="Closing…" compact />
          </div>
          {action.kind === 'login' && (
            <Link to="/login" state={{ from: `/auctions/${auction.id}` }} className="w-full shrink-0 rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-semibold text-white shadow-sm min-[400px]:w-auto">
              {action.label}
            </Link>
          )}
          {action.kind === 'scroll' && (
            <button type="button" onClick={backToBidCard} className="w-full shrink-0 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm min-[400px]:w-auto">
              {action.label}
            </button>
          )}
        </div>
      </div>
    </>
  )
}

/** Whether the element is on screen. Without IntersectionObserver it counts as visible, so nothing extra shows. */
function useInView(ref: RefObject<HTMLElement | null>): boolean {
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    const element = ref.current
    if (!element || typeof IntersectionObserver === 'undefined') {
      return
    }
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting))
    observer.observe(element)
    return () => observer.disconnect()
  }, [ref])

  return visible
}
