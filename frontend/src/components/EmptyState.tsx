import type { ReactNode } from 'react'

/** A friendly "nothing here" message that says why and offers the obvious next step. */
export function EmptyState({ icon, title, children, action }: { icon: string; title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="mx-auto max-w-md py-14 text-center">
      <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50 text-2xl" aria-hidden>
        {icon}
      </div>
      <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
      {children && <p className="mt-1 text-sm text-slate-500">{children}</p>}
      {action && <div className="mt-5 flex justify-center">{action}</div>}
    </div>
  )
}
