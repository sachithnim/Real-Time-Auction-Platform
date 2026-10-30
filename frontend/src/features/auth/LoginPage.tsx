import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Field } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { AuthLayout } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { useAuth } from './useAuth'

const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
  remember: z.boolean(),
})

type Values = z.infer<typeof schema>

export function LoginPage() {
  useDocumentTitle('Log in')
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState, setError: setFieldError } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', remember: false },
  })

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      const user = await login(values)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from ?? (user.role === 'ADMIN' ? '/admin/auctions' : '/'), { replace: true })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      if (fields.email) {
        setFieldError('email', { message: fields.email })
      } else {
        setError(friendlyMessage(info))
      }
    }
  })

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Log in to follow your auctions and keep bidding."
      footer={
        <>
          New to LiveBid?{' '}
          <Link to="/register" className="font-semibold text-indigo-600 hover:underline">
            Create an account
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        {error && <Alert>{error}</Alert>}
        <Field label="Email" type="email" autoComplete="email" placeholder="you@example.com" {...register('email')} error={formState.errors.email?.message} />
        <PasswordField label="Password" autoComplete="current-password" {...register('password')} error={formState.errors.password?.message} />
        <label className="flex cursor-pointer items-start gap-2.5 text-sm text-slate-700 select-none">
          <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-slate-300 accent-indigo-600" {...register('remember')} />
          <span>
            Remember me
            <span className="block text-xs text-slate-500">Stay logged in on this device. Leave unchecked on a shared computer.</span>
          </span>
        </label>
        <Button type="submit" busy={formState.isSubmitting} className="w-full py-2.5">
          Log in
        </Button>
      </form>
    </AuthLayout>
  )
}
