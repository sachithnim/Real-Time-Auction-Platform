import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Field } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { AuthLayout } from './AuthLayout'
import { requestPasswordReset } from './passwordApi'

const schema = z.object({
  email: z.email('Enter a valid email address.'),
})

type Values = z.infer<typeof schema>

/** Step 1 of "Forgot password?": ask for the email and send the link. */
export function ForgotPasswordPage() {
  useDocumentTitle('Forgot password')
  const [sentTo, setSentTo] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '' } })

  const onSubmit = handleSubmit(async ({ email }) => {
    setError(null)
    try {
      await requestPasswordReset(email)
      setSentTo(email)
    } catch (e) {
      const info = parseApiError(e)
      setError(info.status === 429 ? 'Too many requests. Wait a minute, then try again.' : friendlyMessage(info))
    }
  })

  const footer = (
    <>
      Remembered it?{' '}
      <Link to="/login" className="font-semibold text-indigo-600 hover:underline">
        Back to log in
      </Link>
    </>
  )

  if (sentTo) {
    return (
      <AuthLayout title="Check your email" subtitle="The link is on its way." footer={footer}>
        <div className="space-y-4 text-sm text-slate-600" role="status">
          {/* Worded the same whether or not the account exists, like the API's answer. */}
          <p>
            If <span className="font-medium text-slate-900">{sentTo}</span> has a LiveBid account, we have sent it a link to choose a new password.
          </p>
          <p>The link works once and expires in 60 minutes. Can’t find it? Check your spam folder.</p>
          <button type="button" onClick={() => setSentTo(null)} className="font-semibold text-indigo-600 hover:underline">
            Use a different email
          </button>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Forgot your password?" subtitle="Enter your email and we’ll send you a link to choose a new one." footer={footer}>
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        {error && <Alert>{error}</Alert>}
        <Field label="Email" type="email" autoComplete="email" placeholder="you@example.com" {...register('email')} error={formState.errors.email?.message} />
        <Button type="submit" busy={formState.isSubmitting} className="w-full py-2.5">
          Send reset link
        </Button>
      </form>
    </AuthLayout>
  )
}
