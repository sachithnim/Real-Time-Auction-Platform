import { useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { ResendVerification } from './ResendVerification'
import { meQueryKey, useAuth } from './useAuth'
import { readVerifiedStatus } from './verification'

/** Where the "Confirm your email" link lands, after the API has checked it. */
export function EmailVerifiedPage() {
  const status = readVerifiedStatus(useSearchParams()[0].get('status'))
  const { user } = useAuth()
  const queryClient = useQueryClient()
  useDocumentTitle(status === 'invalid' ? 'Link expired' : 'Email confirmed')

  // Confirmed on the server: refresh who is signed in, so the banner goes away here too.
  useEffect(() => {
    if (status !== 'invalid') {
      queryClient.invalidateQueries({ queryKey: meQueryKey })
    }
  }, [status, queryClient])

  const ok = status !== 'invalid'

  return (
    <div className="mx-auto max-w-md py-16 text-center">
      <div className={`mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full ${ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
        <svg viewBox="0 0 24 24" className="h-7 w-7" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          {ok ? <path d="M5 12l5 5L20 7" /> : <path d="M12 8v5M12 16.5v.5M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />}
        </svg>
      </div>
      <h1 className="text-xl font-semibold text-slate-900">
        {status === 'verified' ? 'Email confirmed' : status === 'already' ? 'Already confirmed' : 'This link has expired'}
      </h1>
      <p className="mt-2 text-sm text-slate-500">
        {ok
          ? 'Thank you. You can bid on any live auction now.'
          : 'Confirmation links work for 24 hours, and only for the email they were sent to.'}
      </p>
      <div className="mt-6 flex flex-col items-center gap-3">
        {ok ? (
          <Link to="/?status=LIVE" className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Browse live auctions
          </Link>
        ) : user && !user.email_verified ? (
          <ResendVerification className="text-indigo-700" />
        ) : user ? (
          <Link to="/" className="text-sm font-semibold text-indigo-700 hover:underline">Back to auctions</Link>
        ) : (
          <Link to="/login" state={{ from: '/' }} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Log in to get a new link
          </Link>
        )}
      </div>
    </div>
  )
}
