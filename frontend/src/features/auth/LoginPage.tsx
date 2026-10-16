import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Card, Field } from '../../components/ui'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { useAuth } from './useAuth'

const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
})

type Values = z.infer<typeof schema>

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState, setError: setFieldError } = useForm<Values>({ resolver: zodResolver(schema) })

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
    <div className="mx-auto max-w-sm py-12">
      <Card>
        <h1 className="mb-6 text-xl font-semibold">Log in</h1>
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {error && <Alert>{error}</Alert>}
          <Field label="Email" type="email" autoComplete="email" {...register('email')} error={formState.errors.email?.message} />
          <Field label="Password" type="password" autoComplete="current-password" {...register('password')} error={formState.errors.password?.message} />
          <Button type="submit" busy={formState.isSubmitting} className="w-full">
            Log in
          </Button>
        </form>
        <p className="mt-4 text-center text-sm text-slate-600">
          No account? <Link to="/register" className="font-medium text-indigo-600 hover:underline">Register</Link>
        </p>
      </Card>
    </div>
  )
}
