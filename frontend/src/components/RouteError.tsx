import { isRouteErrorResponse, useRouteError } from 'react-router'
import { NotFoundPage } from './NotFoundPage'

/**
 * Shown instead of the router's developer error screen when a page crashes.
 * Plain links (not router links), so it works even if the app around it is broken.
 */
export function RouteError() {
  const error = useRouteError()

  if (isRouteErrorResponse(error) && error.status === 404) {
    return <NotFoundPage />
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div role="alert" className="max-w-md text-center">
        <img src="/logo.png" alt="" className="mx-auto mb-6 h-14 w-14" />
        <h1 className="text-2xl font-bold text-slate-900">Something went wrong on this page</h1>
        <p className="mt-2 text-sm text-slate-600">
          Your bids are safe: they are stored on the server. Reload the page, or go back to the auctions.
        </p>
        <div className="mt-6 flex justify-center gap-3">
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          >
            Reload page
          </button>
          <a href="/" className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
            Go to auctions
          </a>
        </div>
      </div>
    </div>
  )
}
