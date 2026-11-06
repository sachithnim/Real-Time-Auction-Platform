import { useMutation } from '@tanstack/react-query'
import { parseApiError } from '../../lib/apiError'
import { resendVerification } from './verification'

/** "Send the link again", with what happened in its place: sent, or wait a minute. */
export function ResendVerification({ className = '' }: { className?: string }) {
  const resend = useMutation({ mutationFn: resendVerification })

  if (resend.isSuccess) {
    return (
      <span role="status" className={`font-medium ${className}`}>
        Sent. Check your inbox.
      </span>
    )
  }

  const waited = resend.isError && parseApiError(resend.error).status === 429

  return (
    <button type="button" onClick={() => resend.mutate()} disabled={resend.isPending} className={`font-semibold underline-offset-2 hover:underline disabled:opacity-60 ${className}`}>
      {resend.isPending ? 'Sending…' : waited ? 'Too many tries. Wait a minute' : resend.isError ? 'Could not send. Try again' : 'Send the link again'}
    </button>
  )
}
