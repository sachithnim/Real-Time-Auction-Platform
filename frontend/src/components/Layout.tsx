import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../features/auth/useAuth'
import { NotificationBell } from '../features/realtime/NotificationBell'
import { useUserNotifications } from '../features/realtime/useUserNotifications'
import type { User } from '../types/api'
import { Footer } from './Footer'
import { Logo } from './Logo'
import { ThemeMenu } from './ThemeMenu'
import { usePopover } from './usePopover'
import { Avatar, UserMenu } from './UserMenu'

const navClass = ({ isActive }: { isActive: boolean }) =>
  `whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:text-slate-900'}`

interface NavItem {
  to: string
  label: string
  /** Active only on this exact path (the home link would otherwise match everything). */
  end?: boolean
}

const adminLinks: NavItem[] = [
  { to: '/admin', label: 'Dashboard', end: true },
  { to: '/admin/auctions', label: 'Manage auctions' },
  { to: '/admin/monitor', label: 'Live monitor' },
  { to: '/admin/bidders', label: 'Bidders' },
  { to: '/admin/payments', label: 'Payments' },
]

/** Visited less often: under "More" in the bar, listed in full in the phone menu. */
const adminMoreLinks: NavItem[] = [
  { to: '/admin/categories', label: 'Categories' },
  { to: '/admin/flags', label: 'Flags' },
  { to: '/admin/statistics', label: 'Statistics' },
]

/**
 * When the full bar shows. Admins have more links, so they get the menu button up
 * to a wider screen. Written out in full so Tailwind sees every class.
 */
const breakpoints = {
  default: { bar: 'hidden lg:flex', divider: 'hidden lg:block', narrowOnly: 'lg:hidden' },
  admin: { bar: 'hidden xl:flex', divider: 'hidden xl:block', narrowOnly: 'xl:hidden' },
}

const bidderLinks: NavItem[] = [{ to: '/account/bids', label: 'My bids' }]

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

  const links: NavItem[] = [
    { to: '/', label: 'Auctions', end: true },
    ...(user?.role === 'ADMIN' ? adminLinks : []),
    ...(user?.role === 'BIDDER' ? bidderLinks : []),
  ]
  const closeMenu = () => setMenuOpen(false)
  const isAdmin = user?.role === 'ADMIN'
  const bp = isAdmin ? breakpoints.admin : breakpoints.default

  const onLogout = async () => {
    closeMenu()
    await logout()
    navigate('/login')
  }

  return (
    <div className="flex min-h-screen flex-col">
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
            {/* Below this width the menu button takes over. */}
            <nav className={`${bp.bar} items-center gap-1`} aria-label="Main">
              {links.map((link) => (
                <NavLink key={link.to} to={link.to} end={link.end} className={navClass}>
                  {link.label}
                </NavLink>
              ))}
              {isAdmin && <MoreMenu links={adminMoreLinks} />}
            </nav>
          </div>
          {/* Account first, then the theme menu at the far right: a site-wide setting, kept in the same place on every page. */}
          <div className="flex items-center gap-3">
            <div className={`${bp.bar} items-center gap-3 text-sm`}>
              {user ? <UserMenu user={user} onLogout={onLogout} /> : <Account user={null} onLogout={onLogout} />}
            </div>
            {/* On every screen size: a missed notice should never be behind the phone menu. */}
            {user?.role === 'BIDDER' && <NotificationBell />}
            <span className={`${bp.divider} h-6 w-px bg-slate-200`} aria-hidden />
            <ThemeMenu />
            <button
              type="button"
              className={`rounded-md p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 ${bp.narrowOnly}`}
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
          <div id="mobile-menu" className={`border-t border-slate-100 px-4 pt-2 pb-4 ${bp.narrowOnly}`}>
            <nav className="flex flex-col gap-1" aria-label="Main">
              {[...links, ...(isAdmin ? adminMoreLinks : [])].map((link) => (
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
      <main id="main" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 focus:outline-none">
        <Outlet />
      </main>
      <Footer />
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
      <Link to="/account" className="flex items-center gap-2 text-slate-600 hover:text-slate-900" onClick={onNavigate} aria-label={`Account settings: ${user.name}`}>
        <Avatar user={user} />
        {user.name} <span className="text-xs text-slate-500">({user.role.toLowerCase()})</span>
      </Link>
      <button type="button" className="font-medium text-slate-600 hover:text-slate-900" onClick={onLogout}>
        Log out
      </button>
    </>
  )
}

/** "More ▾": the admin pages that do not fit in the bar. Highlighted while one of them is open. */
function MoreMenu({ links }: { links: NavItem[] }) {
  const { open, rootRef, buttonRef, menuId, toggle, close, buttonProps } = usePopover()
  const { pathname } = useLocation()
  const current = links.some((link) => pathname.startsWith(link.to))

  return (
    <div ref={rootRef} className="relative">
      <button ref={buttonRef} {...buttonProps} onClick={toggle} className={`flex items-center gap-1 ${navClass({ isActive: current })}`}>
        More
        <svg viewBox="0 0 24 24" className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} aria-hidden>
          <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>
      {open && (
        <div id={menuId} role="menu" aria-label="More admin pages" className="absolute left-0 z-40 mt-2 w-44 rounded-lg bg-white p-1 shadow-lg ring-1 ring-slate-200">
          {links.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              role="menuitem"
              onClick={() => close()}
              className={({ isActive }) => `block rounded-md px-2.5 py-2 text-sm ${isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-100'}`}
            >
              {link.label}
            </NavLink>
          ))}
        </div>
      )}
    </div>
  )
}
