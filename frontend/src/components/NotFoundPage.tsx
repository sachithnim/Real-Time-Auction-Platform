import { Link } from 'react-router'
import { EmptyState } from './EmptyState'
import { useDocumentTitle } from './useDocumentTitle'

export function NotFoundPage({ message = 'This page does not exist.' }: { message?: string }) {
  useDocumentTitle('Page not found')
  return (
    <EmptyState
      icon="🧭"
      title={message}
      action={
        <Link to="/" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
          Back to auctions
        </Link>
      }
    >
      The link may be old or mistyped. Every live and upcoming auction is on the auctions page.
    </EmptyState>
  )
}
