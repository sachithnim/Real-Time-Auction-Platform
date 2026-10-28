import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { ToastContext, type Toast } from './useToast'


const tones: Record<Toast['tone'], string> = {
  info: 'border-sky-400',
  success: 'border-emerald-500',
  warning: 'border-amber-500',
  error: 'border-red-500',
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
          <div key={toast.id} role="status" className={`pointer-events-auto rounded-md border-l-4 bg-white p-3 shadow-lg ${tones[toast.tone]}`}>
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm font-semibold text-slate-900">{toast.title}</p>
              <button type="button" onClick={() => dismiss(toast.id)} className="text-slate-500 hover:text-slate-700" aria-label="Dismiss">
                ×
              </button>
            </div>
            {toast.body && <p className="mt-1 text-sm text-slate-600">{toast.body}</p>}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}
