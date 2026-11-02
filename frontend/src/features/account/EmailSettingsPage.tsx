import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ErrorState } from '../../components/ErrorState'
import { RowsSkeleton } from '../../components/Skeleton'
import { Card } from '../../components/ui'
import { useDocumentTitle } from '../../components/useDocumentTitle'
import { useToast } from '../../components/useToast'
import { friendlyMessage, parseApiError } from '../../lib/apiError'
import { useAuth } from '../auth/useAuth'
import { emailPreferencesKey, emailTypes, fetchEmailPreferences, updateEmailPreferences, type EmailPreferences, type EmailType } from './emailPreferences'

/** Switch each notification email on or off. Changes save at once. */
export function EmailSettingsPage() {
  useDocumentTitle('Email settings')
  const { user } = useAuth()
  const toast = useToast()
  const queryClient = useQueryClient()
  const preferences = useQuery({ queryKey: emailPreferencesKey, queryFn: fetchEmailPreferences })

  const save = useMutation({
    mutationFn: (change: Partial<EmailPreferences>) => updateEmailPreferences(change),
    // The switch moves at once; a failure puts it back.
    onMutate: async (change) => {
      await queryClient.cancelQueries({ queryKey: emailPreferencesKey })
      const before = queryClient.getQueryData<EmailPreferences>(emailPreferencesKey)
      queryClient.setQueryData<EmailPreferences>(emailPreferencesKey, (current) => (current ? { ...current, ...change } : current))
      return { before }
    },
    onSuccess: (saved) => {
      queryClient.setQueryData(emailPreferencesKey, saved)
      toast.push({ tone: 'success', title: 'Email settings saved' })
    },
    onError: (e, _change, context) => {
      queryClient.setQueryData(emailPreferencesKey, context?.before)
      toast.push({ tone: 'error', title: 'Not saved', body: friendlyMessage(parseApiError(e)) })
    },
  })

  const allOff = preferences.data ? emailTypes.every(({ type }) => !preferences.data[type]) : false

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Email settings</h1>
        <p className="mt-1 text-sm text-slate-500">
          Choose which emails we send to <span className="font-medium text-slate-700">{user?.email}</span>. You still see every update live on the site.
        </p>
      </div>

      {preferences.isPending && <RowsSkeleton rows={5} label="Loading email settings" />}
      {preferences.isError && (
        <ErrorState title="Could not load your email settings" onRetry={() => preferences.refetch()}>
          {friendlyMessage(parseApiError(preferences.error))}
        </ErrorState>
      )}

      {preferences.data && (
        <Card className="divide-y divide-slate-100 p-0">
          {emailTypes.map(({ type, label, description }) => (
            <Toggle
              key={type}
              type={type}
              label={label}
              description={description}
              checked={preferences.data[type]}
              onChange={(on) => save.mutate({ [type]: on })}
            />
          ))}
        </Card>
      )}

      {preferences.data && (
        <div className="flex flex-wrap justify-end gap-3 text-sm">
          {allOff ? (
            <button type="button" className="font-medium text-indigo-700 hover:underline" onClick={() => save.mutate(Object.fromEntries(emailTypes.map(({ type }) => [type, true])))}>
              Turn all emails on
            </button>
          ) : (
            <button type="button" className="font-medium text-slate-600 hover:text-slate-900 hover:underline" onClick={() => save.mutate(Object.fromEntries(emailTypes.map(({ type }) => [type, false])))}>
              Turn all emails off
            </button>
          )}
        </div>
      )}
    </div>
  )
}

function Toggle({ type, label, description, checked, onChange }: { type: EmailType; label: string; description: string; checked: boolean; onChange: (on: boolean) => void }) {
  const id = `email-${type}`
  return (
    <div className="flex items-start justify-between gap-4 px-5 py-4">
      <div>
        <label htmlFor={id} className="text-sm font-medium text-slate-900">
          {label}
        </label>
        <p id={`${id}-description`} className="text-sm text-slate-500">
          {description}
        </p>
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-describedby={`${id}-description`}
        onClick={() => onChange(!checked)}
        className={`relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition ${checked ? 'bg-indigo-600' : 'bg-slate-300'}`}
      >
        <span className={`inline-block h-5 w-5 rounded-full bg-[#ffffff] shadow transition ${checked ? 'translate-x-5' : 'translate-x-0.5'}`} aria-hidden />
      </button>
    </div>
  )
}
