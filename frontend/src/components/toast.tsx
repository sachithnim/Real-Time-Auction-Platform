import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { ToastContext, type Toast } from './useToast'


const tones: Record<Toast['tone'], string> = {
  info: 'border-sky-400',
  success: 'border-emerald-500',
  warning: 'border-amber-500',
  error: 'border-red-500',
}

const iconColours: Record<Toast['tone'], string> = {
  info: 'text-sky-600',
  success: 'text-emerald-600',
  warning: 'text-amber-600',
  error: 'text-red-600',
}

// Check, clock-free "i", warning triangle, cross: the tone is not told by colour alone.
const iconPaths: Record<Toast['tone'], string> = {
  info: 'M12 16v-5M12 8h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
  success: 'M8 12.5l3 3 5-6M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
  warning: 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  error: 'M15 9l-6 6M9 9l6 6M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
}

let nextId = 1

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])

  const dismiss = useCallback((id: number) => setToasts((current) => current.filter((t) => t.id !== id)), [])

  const push = useCallback(
    (toast: Omit<Toast, 'id'>) => {
      const id = nextId++
      setToasts((current) => [...current.slice(-3), { ...toast, id }])
      window.setTimeout(() => dismiss(id), 6_000)
    },
    [dismiss],
  )

  const value = useMemo(() => ({ push }), [push])

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 flex-col gap-2" aria-live="polite">
        {toasts.map((toast) => (
          <div key={toast.id} role="status" className={`pointer-events-auto flex gap-3 rounded-md border-l-4 bg-white p-3 shadow-lg ring-1 ring-slate-200 ${tones[toast.tone]}`}>
            <svg viewBox="0 0 24 24" className={`mt-0.5 h-5 w-5 shrink-0 ${iconColours[toast.tone]}`} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d={iconPaths[toast.tone]} />
            </svg>
            <div className="min-w-0 flex-1">
              <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-semibold text-slate-900">{toast.title}</p>
                <button type="button" onClick={() => dismiss(toast.id)} className="text-slate-500 hover:text-slate-700" aria-label="Dismiss">
                  ×
                </button>
              </div>
              {toast.body && <p className="mt-1 text-sm text-slate-600">{toast.body}</p>}
              {toast.action && (
                <button
                  type="button"
                  onClick={() => {
                    dismiss(toast.id)
                    toast.action!.onClick()
                  }}
                  className="mt-2 text-sm font-semibold text-indigo-600 hover:underline"
                >
                  {toast.action.label}
                </button>
              )}
            </div>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
