import { zodResolver } from '@hookform/resolvers/zod'
import { useQueryClient } from '@tanstack/react-query'
import { useForm, useWatch } from 'react-hook-form'
import { Link } from 'react-router'
import { z } from 'zod'
import { Button, Card, Field } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useToast } from '../../components/useToast'
import { fieldErrors, friendlyMessage, parseApiError } from '../../lib/apiError'
import { passwordStrength } from '../../lib/passwordStrength'
import type { User } from '../../types/api'
import { PasswordField } from '../auth/PasswordField'
import { StrengthMeter } from '../auth/StrengthMeter'
import { meQueryKey, useAuth } from '../auth/useAuth'
import { updatePassword, updateProfile } from './accountApi'

/** Name, email and password for any signed-in user, admin or bidder. */
export function AccountPage() {
  useDocumentTitle('Account settings')
  const { user } = useAuth()

  if (!user) {
    return null
  }

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Account settings</h1>
        <p className="mt-1 text-sm text-slate-500">Your name, the email you log in with, and your password.</p>
      </div>
      {/* Keyed by the saved details, so the form starts again from them after a save. */}
      <ProfileForm key={`${user.first_name}|${user.last_name}|${user.email}`} user={user} />
      <PasswordForm />
      {user.role === 'BIDDER' && (
        <p className="text-sm text-slate-500">
          Looking for notification emails?{' '}
          <Link to="/account/emails" className="font-medium text-indigo-700 hover:underline">
            Email settings
          </Link>
        </p>
      )}
    </div>
  )
}

const profileSchema = z.object({
  first_name: z.string().trim().min(1, 'Enter your first name.').max(50, 'Use at most 50 characters.'),
  last_name: z.string().trim().min(1, 'Enter your last name.').max(50, 'Use at most 50 characters.'),
  email: z.email('Enter a valid email address.'),
  current_password: z.string(),
})

type ProfileValues = z.infer<typeof profileSchema>

const sameEmail = (a: string, b: string) => a.trim().toLowerCase() === b.trim().toLowerCase()

function ProfileForm({ user }: { user: User }) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const { register, handleSubmit, formState, control, setError } = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { first_name: user.first_name ?? '', last_name: user.last_name ?? '', email: user.email, current_password: '' },
  })
  const email = useWatch({ control, name: 'email' }) ?? ''
  // The email is how the account logs in, so changing it asks for the password.
  const emailChanging = !sameEmail(email, user.email)

  const onSubmit = handleSubmit(async (values) => {
    if (emailChanging && !values.current_password) {
      setError('current_password', { message: 'Enter your current password to change your email.' })
      return
    }
    try {
      const saved = await updateProfile({ ...values, current_password: emailChanging ? values.current_password : undefined })
      queryClient.setQueryData(meQueryKey, saved)
      toast.push({ tone: 'success', title: 'Profile saved', body: emailChanging ? `Log in with ${saved.email} from now on.` : undefined })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      const known = (['first_name', 'last_name', 'email', 'current_password'] as const).filter((field) => fields[field])
      known.forEach((field) => setError(field, { message: fields[field] }))
      if (known.length === 0) {
        toast.push({ tone: 'error', title: 'Not saved', body: friendlyMessage(info) })
      }
    }
  })

  return (
    <Card>
      <form onSubmit={onSubmit} className="space-y-5" noValidate aria-labelledby="profile-heading">
        <h2 id="profile-heading" className="text-base font-semibold">
          Profile
        </h2>
        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="First name" autoComplete="given-name" {...register('first_name')} error={formState.errors.first_name?.message} />
          <Field label="Last name" autoComplete="family-name" {...register('last_name')} error={formState.errors.last_name?.message} />
        </div>
        <Field label="Email" type="email" autoComplete="email" {...register('email')} error={formState.errors.email?.message} />
        {emailChanging && (
          <PasswordField
            label="Current password"
            // Its own id: the password form below has a current_password field too.
            id="profile-current-password"
            autoComplete="current-password"
            hint="Needed to change the email you log in with. We’ll tell your old address too."
            {...register('current_password')}
            error={formState.errors.current_password?.message}
          />
        )}
        <div className="flex justify-end">
          <Button type="submit" busy={formState.isSubmitting} disabled={!formState.isDirty}>
            Save profile
          </Button>
        </div>
      </form>
    </Card>
  )
}

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.'),
    password: z.string().min(8, 'Use at least 8 characters.'),
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match.',
  })
  .refine((values) => values.password !== values.current_password, {
    path: ['password'],
    message: 'Choose a password different from your current one.',
  })

type PasswordValues = z.infer<typeof passwordSchema>

function PasswordForm() {
  const toast = useToast()
  const { register, handleSubmit, formState, control, setError, reset } = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const password = useWatch({ control, name: 'password' }) ?? ''

  const onSubmit = handleSubmit(async (values) => {
    try {
      await updatePassword(values)
      reset()
      toast.push({ tone: 'success', title: 'Password changed', body: 'You stay logged in here. Other devices were signed out.' })
    } catch (e) {
      const info = parseApiError(e)
      const fields = fieldErrors(info)
      const known = (['current_password', 'password'] as const).filter((field) => fields[field])
      known.forEach((field) => setError(field, { message: fields[field] }))
      if (known.length === 0) {
        toast.push({ tone: 'error', title: 'Password not changed', body: info.status === 429 ? 'Too many attempts. Wait a minute, then try again.' : friendlyMessage(info) })
      }
    }
  })

  return (
    <Card>
      <form onSubmit={onSubmit} className="space-y-5" noValidate aria-labelledby="password-heading">
        <div>
          <h2 id="password-heading" className="text-base font-semibold">
            Password
          </h2>
          <p className="mt-1 text-sm text-slate-500">Changing it signs you out on every other device.</p>
        </div>
        <PasswordField label="Current password" autoComplete="current-password" {...register('current_password')} error={formState.errors.current_password?.message} />
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
        <div className="flex items-center justify-between gap-3">
          <Link to="/forgot-password" className="text-sm font-medium text-indigo-700 hover:underline">
            Forgot your current password?
          </Link>
          <Button type="submit" busy={formState.isSubmitting}>
            Change password
          </Button>
        </div>
      </form>
    </Card>
  )
}
