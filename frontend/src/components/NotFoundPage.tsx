import { Link } from 'react-router'
import { useDocumentTitle } from './useDocumentTitle'

export function NotFoundPage({ message = 'This page does not exist.' }: { message?: string }) {
  useDocumentTitle('Page not found')
  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <p className="bg-linear-to-r from-indigo-600 to-orange-500 bg-clip-text text-7xl font-extrabold tracking-tight text-transparent" aria-hidden>
        404
      </p>
      <h1 className="mt-4 text-xl font-semibold text-slate-900">{message}</h1>
      <p className="mt-2 text-sm text-slate-500">The link may be old or mistyped. Every live and upcoming auction is on the auctions page.</p>
      <div className="mt-6 flex justify-center gap-3">
        <Link to="/" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
          Back to auctions
        </Link>
        <Link to="/?status=live" className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
          See live auctions
        </Link>
      </div>
    </div>
  )
}
