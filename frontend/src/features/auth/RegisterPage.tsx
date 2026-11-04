import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'
import { z } from 'zod'
import { Alert, Button, Field } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { passwordStrength } from '../../lib/passwordStrength'
import { AuthLayout } from './AuthLayout'
import { PasswordField } from './PasswordField'
import { StrengthMeter } from './StrengthMeter'
import { useAuth } from './useAuth'

const schema = z
  .object({
    first_name: z.string().trim().min(1, 'Enter your first name.').max(50, 'Use at most 50 characters.'),
    last_name: z.string().trim().min(1, 'Enter your last name.').max(50, 'Use at most 50 characters.'),
    email: z.email('Enter a valid email address.'),
    password: z.string().min(8, 'Use at least 8 characters.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  })

type Values = z.infer<typeof schema>

const serverFields = ['first_name', 'last_name', 'email', 'password'] as const

export function RegisterPage() {
  useDocumentTitle('Create an account')
  const { register: registerUser } = useAuth()
  const navigate = useNavigate()
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState, control, setError: setFieldError } = useForm<Values>({ resolver: zodResolver(schema) })
  const password = useWatch({ control, name: 'password' }) ?? ''

  const onSubmit = handleSubmit(async (values) => {
    setError(null)
    try {
      await registerUser(values)
      navigate('/', { replace: true })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      const known = serverFields.filter((field) => fields[field])
      known.forEach((field) => setFieldError(field, { message: fields[field] }))
      if (known.length === 0) {
        setError(friendlyMessage(info))
      }
    }
  })

  return (
    <AuthLayout
      title="Create your account"
      subtitle="Join in a minute and start bidding on live auctions."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/login" className="font-semibold text-indigo-600 hover:underline">
            Log in
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="space-y-5" noValidate>
        {error && <Alert>{error}</Alert>}
        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="First name" autoComplete="given-name" {...register('first_name')} error={formState.errors.first_name?.message} />
          <Field label="Last name" autoComplete="family-name" {...register('last_name')} error={formState.errors.last_name?.message} />
        </div>
        <Field label="Email" type="email" autoComplete="email" placeholder="you@example.com" {...register('email')} error={formState.errors.email?.message} />
        <div className="space-y-2">
          <PasswordField
            label="Password"
            autoComplete="new-password"
            {...register('password')}
            error={formState.errors.password?.message}
            hint={password ? undefined : 'At least 8 characters.'}
          />
          {password && !formState.errors.password && <StrengthMeter strength={passwordStrength(password)} />}
        </div>
        <PasswordField
          label="Confirm password"
          autoComplete="new-password"
          {...register('password_confirmation')}
          error={formState.errors.password_confirmation?.message}
        />
        <Button type="submit" busy={formState.isSubmitting} className="w-full py-2.5">
          Create account
        </Button>
      </form>
    </AuthLayout>
  )
}
