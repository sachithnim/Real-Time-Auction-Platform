import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Card, Field } from '../../components/ui'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { useAuth } from './useAuth'

const schema = z
  .object({
    name: z.string().trim().min(1, 'Enter your name.').max(100),
    email: z.email('Enter a valid email address.'),
    password: z.string().min(8, 'Use at least 8 characters.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  })

type Values = z.infer<typeof schema>

export function RegisterPage() {
  const { register: registerUser } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState, setError: setFieldError } = useForm<Values>({ resolver: zodResolver(schema) })

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      await registerUser(values)
      navigate('/', { replace: true })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      const known = (['name', 'email', 'password'] as const).filter((field) => fields[field])
      known.forEach((field) => setFieldError(field, { message: fields[field] }))
      if (known.length === 0) {
        setError(friendlyMessage(info))
      }
    }
  })

  return (
    <div className="mx-auto max-w-sm py-12">
      <Card>
        <h1 className="mb-6 text-xl font-semibold">Create an account</h1>
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          {error && <Alert>{error}</Alert>}
          <Field label="Name" autoComplete="name" {...register('name')} error={formState.errors.name?.message} />
          <Field label="Email" type="email" autoComplete="email" {...register('email')} error={formState.errors.email?.message} />
          <Field label="Password" type="password" autoComplete="new-password" {...register('password')} error={formState.errors.password?.message} />
          <Field
            label="Confirm password"
            type="password"
            autoComplete="new-password"
            {...register('password_confirmation')}
            error={formState.errors.password_confirmation?.message}
          />
          <Button type="submit" busy={formState.isSubmitting} className="w-full">
            Register
          </Button>
        </form>
        <p className="mt-4 text-center text-sm text-slate-600">
          Already registered? <Link to="/login" className="font-medium text-indigo-600 hover:underline">Log in</Link>
        </p>
      </Card>
    </div>
  )
}
