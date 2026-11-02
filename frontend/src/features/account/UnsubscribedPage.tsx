import { Link, useSearchParams } from 'react-router'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useAuth } from '../auth/useAuth'
import { emailTypes } from './emailPreferences'

/** Where the unsubscribe link in an email lands, after the API has switched that email off. */
export function UnsubscribedPage() {
  useDocumentTitle('Unsubscribed')
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const type = emailTypes.find((t) => t.type === searchParams.get('type'))

  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
        <svg viewBox="0 0 24 24" className="h-7 w-7" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M4 6h16v12H4zM4 7l8 6 8-6" />
        </svg>
      </div>
      <h1 className="text-xl font-semibold text-slate-900">You are unsubscribed</h1>
      <p className="mt-2 text-sm text-slate-500">
        {type ? `We won't send you "${type.label}" emails any more.` : "We won't send you those emails any more."} You can turn them back on at any time.
      </p>
      <div className="mt-6 flex justify-center gap-3">
        <Link
          to={user ? '/account/emails' : '/login'}
          state={user ? undefined : { from: '/account/emails' }}
          className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
        >
          Manage email settings
        </Link>
        <Link to="/" className="rounded-md bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
          Back to auctions
        </Link>
      </div>
    </div>
  )
}
