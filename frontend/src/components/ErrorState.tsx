import type { ReactNode } from 'react'
import { Button } from './ui'

/** Something could not load: says so plainly, with a retry when there is one. */
export function ErrorState({ title = 'Something went wrong', children, onRetry }: { title?: string; children?: ReactNode; onRetry?: () => void }) {
  return (
    <div role="alert" className="mx-auto max-w-md py-14 text-center">
      <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-600">
        <svg viewBox="0 0 24 24" className="h-7 w-7" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
        </svg>
      </div>
      <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
      {children && <p className="mt-1 text-sm text-slate-500">{children}</p>}
      {onRetry && (
        <div className="mt-5 flex justify-center">
          <Button variant="secondary" onClick={onRetry}>
            Try again
          </Button>
        </div>
      )}
    </div>
  )
}
