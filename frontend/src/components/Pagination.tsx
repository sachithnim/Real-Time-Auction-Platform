import { pageItems, pageRange } from '../lib/pagination'
import { Spinner } from './ui'

interface PaginationProps {
  page: number
  lastPage: number
  perPage: number
  total: number
  /** What is being counted, singular and plural: ['auction', 'auctions']. */
  noun: [string, string]
  onChange: (page: number) => void
}

const arrow = 'flex h-9 w-9 items-center justify-center rounded-md text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent'

/**
 * "Showing 13–24 of 40 auctions" and numbered page buttons (only when there is more
 * than one page). Changing page scrolls back to the top, where the new results start.
 */
export function Pagination({ page, lastPage, perPage, total, noun, onChange }: PaginationProps) {
  const { from, to } = pageRange(page, perPage, total)
  const go = (next: number) => {
    onChange(next)
    const smooth = !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    window.scrollTo?.({ top: 0, behavior: smooth ? 'smooth' : 'auto' })
  }

  return (
    <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-200 pt-4 sm:flex-row">
      <p className="text-sm text-slate-500" aria-live="polite">
        {total === 0 ? (
          `No ${noun[1]}`
        ) : (
          <>
            Showing <span className="font-medium text-slate-700">{from}</span>–<span className="font-medium text-slate-700">{to}</span> of{' '}
            <span className="font-medium text-slate-700">{total}</span> {total === 1 ? noun[0] : noun[1]}
          </>
        )}
      </p>

      {lastPage > 1 && (
        <nav aria-label="Pagination" className="flex items-center gap-1">
          <button type="button" className={arrow} disabled={page <= 1} onClick={() => go(page - 1)} aria-label="Previous page">
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="M15 18l-6-6 6-6" />
            </svg>
          </button>
          {pageItems(page, lastPage).map((item, i) =>
            item === 'gap' ? (
              <span key={`gap-${i}`} className="w-9 text-center text-slate-400" aria-hidden>
                …
              </span>
            ) : (
              <button
                key={item}
                type="button"
                onClick={() => go(item)}
                aria-label={`Page ${item}`}
                aria-current={item === page ? 'page' : undefined}
                className={`h-9 min-w-9 rounded-md px-2 text-sm font-medium tabular-nums ${
                  item === page ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                }`}
              >
                {item}
              </button>
            ),
          )}
          <button type="button" className={arrow} disabled={page >= lastPage} onClick={() => go(page + 1)} aria-label="Next page">
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="M9 18l6-6-6-6" />
            </svg>
          </button>
        </nav>
      )}
    </div>
  )
}

/** Fetches the next batch of an older-first list (bids, audit events) and adds it below. */
export function LoadMore({ onClick, loading, label = 'Load more' }: { onClick: () => void; loading: boolean; label?: string }) {
  return (
    <div className="flex justify-center pt-3">
      <button
        type="button"
        onClick={onClick}
        disabled={loading}
        aria-busy={loading}
        className="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium text-indigo-700 ring-1 ring-slate-200 hover:bg-slate-50 disabled:opacity-60"
      >
        {loading && <Spinner />}
        {loading ? 'Loading…' : label}
      </button>
    </div>
  )
}
