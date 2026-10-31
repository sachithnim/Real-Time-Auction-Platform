import { Link } from 'react-router'
import { initials } from '../lib/initials'
import type { User } from '../types/api'
import { usePopover } from './usePopover'

const itemClass = 'flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm text-slate-700 hover:bg-slate-100'

/** The signed-in user's avatar button and account menu (desktop header). */
export function UserMenu({ user, onLogout }: { user: User; onLogout: () => void }) {
  const { open, rootRef, buttonRef, menuId, toggle, close, buttonProps } = usePopover()

  return (
    <div ref={rootRef} className="relative">
      <button
        ref={buttonRef}
        {...buttonProps}
        onClick={toggle}
        aria-label={`Account: ${user.name}`}
        className="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 text-sm font-medium text-slate-700 ring-1 ring-slate-200 transition hover:bg-slate-100"
      >
        <Avatar user={user} />
        <span className="max-w-32 truncate">{user.first_name ?? user.name}</span>
        <svg viewBox="0 0 24 24" className={`h-4 w-4 text-slate-500 transition ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} aria-hidden>
          <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {open && (
        <div id={menuId} role="menu" aria-label="Account" className="absolute right-0 z-40 mt-2 w-64 rounded-lg bg-white p-1 shadow-lg ring-1 ring-slate-200">
          <div className="flex items-center gap-3 px-2.5 py-3">
            <Avatar user={user} large />
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-slate-900">{user.name}</p>
              <p className="truncate text-xs text-slate-500">{user.email}</p>
              <span className="mt-1 inline-block rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                {user.role === 'ADMIN' ? 'Admin' : 'Bidder'}
              </span>
            </div>
          </div>
          <div className="my-1 h-px bg-slate-100" />
          {user.role === 'BIDDER' && (
            <Link to="/?status=watching" role="menuitem" className={itemClass} onClick={() => close()}>
              <svg viewBox="0 0 24 24" className="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" strokeWidth={2} strokeLinejoin="round" aria-hidden>
                <path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z" />
              </svg>
              My watchlist
            </Link>
          )}
          <button
            type="button"
            role="menuitem"
            className={`${itemClass} text-red-700 hover:bg-red-50`}
            onClick={() => {
              close()
              onLogout()
            }}
          >
            <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11" />
            </svg>
            Log out
          </button>
        </div>
      )}
    </div>
  )
}

export function Avatar({ user, large = false }: { user: User; large?: boolean }) {
  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-full bg-indigo-600 font-semibold text-white ${large ? 'h-10 w-10 text-sm' : 'h-7 w-7 text-xs'}`}
      aria-hidden
    >
      {initials(user)}
    </span>
  )
}
