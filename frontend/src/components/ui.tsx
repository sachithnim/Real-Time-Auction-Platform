import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react'
import { forwardRef } from 'react'
import type { AuctionStatus } from '../types/api'

type ButtonVariant = 'primary' | 'secondary' | 'danger'

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-indigo-600 text-white hover:bg-indigo-500 disabled:bg-indigo-300',
  secondary: 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:text-slate-400',
  danger: 'bg-red-600 text-white hover:bg-red-500 disabled:bg-red-300',
}

export function Button({
  variant = 'primary',
  busy = false,
  className = '',
  children,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; busy?: boolean }) {
  return (
    <button
      {...props}
      disabled={props.disabled || busy}
      aria-busy={busy}
      className={`inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-semibold shadow-sm transition disabled:cursor-not-allowed ${variants[variant]} ${className}`}
    >
      {busy && <Spinner />}
      {children}
    </button>
  )
}

export function Spinner() {
  return <span className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden />
}

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  error?: string
  hint?: ReactNode
}

export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field({ label, error, hint, id, ...props }, ref) {
  const inputId = id ?? props.name
  return (
    <div className="space-y-1">
      <label htmlFor={inputId} className="block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        ref={ref}
        id={inputId}
        aria-invalid={error ? true : undefined}
        // Screen readers read the error, or else the hint (e.g. the minimum bid), after the label.
        aria-describedby={error ? `${inputId}-error` : hint ? `${inputId}-hint` : undefined}
        className={`block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 ${error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:ring-indigo-200'}`}
        {...props}
      />
      {hint && !error && (
        <p id={`${inputId}-hint`} className="text-xs text-slate-500">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${inputId}-error`} role="alert" className="text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  )
})

export function Alert({ tone = 'error', children }: { tone?: 'error' | 'info' | 'success' | 'warning'; children: ReactNode }) {
  const tones = {
    error: 'bg-red-50 text-red-800 ring-red-200',
    info: 'bg-sky-50 text-sky-800 ring-sky-200',
    success: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    warning: 'bg-amber-50 text-amber-900 ring-amber-200',
  }
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-md px-4 py-3 text-sm ring-1 ${tones[tone]}`}>
      {children}
    </div>
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 ${className}`}>{children}</div>
}

const statusStyles: Record<AuctionStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-700',
  SCHEDULED: 'bg-sky-100 text-sky-800',
  LIVE: 'bg-emerald-100 text-emerald-800',
  COMPLETED: 'bg-violet-100 text-violet-800',
  CANCELLED: 'bg-red-100 text-red-800',
}

export function StatusBadge({ status }: { status: AuctionStatus }) {
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusStyles[status]}`}>
      {status === 'LIVE' && <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-600" />}
      {status}
    </span>
  )
}


export function PageLoader() {
  return (
    <div className="flex justify-center py-16 text-slate-400">
      <Spinner />
    </div>
  )
}
