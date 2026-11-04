import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { z } from 'zod'
import { Alert, Button } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { passwordStrength } from '../../lib/passwordStrength'
import { AuthLayout } from './AuthLayout'
import { resetPassword } from './passwordApi'
import { PasswordField } from './PasswordField'
import { StrengthMeter } from './StrengthMeter'

const schema = z
  .object({
    password: z.string().min(8, 'Use at least 8 characters.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  })

type Values = z.infer<typeof schema>

/** Step 2 of "Forgot password?": the page the emailed link opens. */
export function ResetPasswordPage() {
  useDocumentTitle('Choose a new password')
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''
  const [error, setError] = useState<string | null>(null)
  const [linkInvalid, setLinkInvalid] = useState(!token || !email)
  const { register, handleSubmit, formState, control, setError: setFieldError } = useForm<Values>({ resolver: zodResolver(schema) })
  const password = useWatch({ control, name: 'password' }) ?? ''

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      await resetPassword({ token, email, ...values })
      navigate('/login', { replace: true, state: { notice: 'Your password was changed. Log in with the new one.', email } })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      if (fields.token || fields.email) {
        setLinkInvalid(true)
      } else if (fields.password) {
        setFieldError('password', { message: fields.password })
      } else {
        setError(friendlyMessage(info))
      }
    }
  })

  if (linkInvalid) {
    return (
      <AuthLayout
        title="This link has expired"
        subtitle="Reset links work once and only for 60 minutes."
        footer={
          <Link to="/login" className="font-semibold text-indigo-600 hover:underline">
            Back to log in
          </Link>
        }
      >
        <div className="space-y-5">
          <Alert tone="warning">This reset link is invalid or has expired. Ask for a new one and use the newest email.</Alert>
          <Link to="/forgot-password" className="block w-full rounded-md bg-indigo-600 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
            Send a new link
          </Link>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Choose a new password"
      subtitle={`For ${email}. You’ll be signed out everywhere else.`}
      footer={
        <Link to="/login" className="font-semibold text-indigo-600 hover:underline">
          Back to log in
        </Link>
      }
    >
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        {error && <Alert>{error}</Alert>}
        <div className="space-y-2">
          <PasswordField
            label="New password"
            autoComplete="new-password"
            {...register('password')}
            error={formState.errors.password?.message}
            hint={password ? undefined : 'At least 8 characters.'}
          />
          {password && !formState.errors.password && <StrengthMeter strength={passwordStrength(password)} />}
        </div>
        <PasswordField label="Confirm new password" autoComplete="new-password" {...register('password_confirmation')} error={formState.errors.password_confirmation?.message} />
        <Button type="submit" busy={formState.isSubmitting} className="w-full py-2.5">
          Save new password
        </Button>
      </form>
    </AuthLayout>
  )
}
