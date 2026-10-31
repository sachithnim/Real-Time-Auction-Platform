import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router'
import { useAuth } from '../features/auth/useAuth'
import { useUserNotifications } from '../features/realtime/useUserNotifications'
import type { User } from '../types/api'
import { Logo } from './Logo'
import { ThemeMenu } from './ThemeMenu'
import { Avatar, UserMenu } from './UserMenu'

const navClass = ({ isActive }: { isActive: boolean }) =>
  `rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:text-slate-900'}`

interface NavItem {
  to: string
  label: string
  /** Active only on this exact path (the home link would otherwise match everything). */
  end?: boolean
}

const adminLinks: NavItem[] = [
  { to: '/admin/auctions', label: 'Manage auctions' },
  { to: '/admin/monitor', label: 'Live monitor' },
  { to: '/admin/categories', label: 'Categories' },
  { to: '/admin/flags', label: 'Flags' },
  { to: '/admin/statistics', label: 'Statistics' },
]

export function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)
  useUserNotifications(user?.role === 'BIDDER' ? user.id : null)

  // Escape closes the phone menu, like any other popup.
  useEffect(() => {
    if (!menuOpen) {
      return
    }
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setMenuOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [menuOpen])

  const links: NavItem[] = [{ to: '/', label: 'Auctions', end: true }, ...(user?.role === 'ADMIN' ? adminLinks : [])]
  const closeMenu = () => setMenuOpen(false)

  const onLogout = async () => {
    closeMenu()
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen">
      <a
        href="#main"
        className="sr-only rounded-md bg-indigo-600 text-sm font-semibold text-white shadow-lg focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:px-4 focus:py-2"
      >
        Skip to content
      </a>
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
          <div className="flex items-center gap-6">
            <Link to="/" aria-label="LiveBid home" onClick={closeMenu}>
              <Logo />
            </Link>
            {/* The full bar needs room for six admin links; below that the menu button takes over. */}
            <nav className="hidden gap-1 lg:flex" aria-label="Main">
              {links.map((link) => (
                <NavLink key={link.to} to={link.to} end={link.end} className={navClass}>
                  {link.label}
                </NavLink>
              ))}
            </nav>
          </div>
          {/* Account first, then the theme menu at the far right: a site-wide setting, kept in the same place on every page. */}
          <div className="flex items-center gap-3">
            <div className="hidden items-center gap-3 text-sm lg:flex">
              {user ? <UserMenu user={user} onLogout={onLogout} /> : <Account user={null} onLogout={onLogout} />}
            </div>
            <span className="hidden h-6 w-px bg-slate-200 lg:block" aria-hidden />
            <ThemeMenu />
            <button
              type="button"
              className="rounded-md p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 lg:hidden"
              aria-expanded={menuOpen}
              aria-controls="mobile-menu"
              aria-label={menuOpen ? 'Close menu' : 'Open menu'}
              onClick={() => setMenuOpen((open) => !open)}
            >
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden>
                {menuOpen ? <path d="M6 6l12 12M18 6L6 18" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
              </svg>
            </button>
          </div>
        </div>

        {menuOpen && (
          <div id="mobile-menu" className="border-t border-slate-100 px-4 pt-2 pb-4 lg:hidden">
            <nav className="flex flex-col gap-1" aria-label="Main">
              {links.map((link) => (
                <NavLink key={link.to} to={link.to} end={link.end} className={navClass} onClick={closeMenu}>
                  {link.label}
                </NavLink>
              ))}
            </nav>
            <div className="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 text-sm">
              <Account user={user} onLogout={onLogout} onNavigate={closeMenu} />
            </div>
          </div>
        )}
      </header>
      {/* tabIndex lets the skip link move focus here, not only scroll. */}
      <main id="main" tabIndex={-1} className="mx-auto max-w-6xl px-4 py-6 focus:outline-none">
        <Outlet />
      </main>
    </div>
  )
}

function Account({ user, onLogout, onNavigate }: { user: User | null; onLogout: () => void; onNavigate?: () => void }) {
  if (!user) {
    return (
      <>
        <Link to="/login" className="font-medium text-slate-600 hover:text-slate-900" onClick={onNavigate}>Log in</Link>
        <Link to="/register" className="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-500" onClick={onNavigate}>
          Register
        </Link>
      </>
    )
  }

  return (
    <>
      <span className="flex items-center gap-2 text-slate-600">
        <Avatar user={user} />
        {user.name} <span className="text-xs text-slate-500">({user.role.toLowerCase()})</span>
      </span>
      <button type="button" className="font-medium text-slate-600 hover:text-slate-900" onClick={onLogout}>
        Log out
      </button>
    </>
  )
}
