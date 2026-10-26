import { Link, NavLink, Outlet, useNavigate } from 'react-router'
import { useAuth } from '../features/auth/useAuth'
import { useUserNotifications } from '../features/realtime/useUserNotifications'

const navClass = ({ isActive }: { isActive: boolean }) =>
  `rounded-md px-3 py-2 text-sm font-medium ${isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:text-slate-900'}`

export function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  useUserNotifications(user?.role === 'BIDDER' ? user.id : null)

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <div className="flex items-center gap-6">
            <Link to="/" className="text-lg font-bold text-indigo-700">
              LiveBid
            </Link>
            <nav className="flex gap-1">
              <NavLink to="/" end className={navClass}>
                Auctions
              </NavLink>
              {user?.role === 'ADMIN' && (
                <>
                  <NavLink to="/admin/auctions" className={navClass}>
                    Manage auctions
                  </NavLink>
                  <NavLink to="/admin/monitor" className={navClass}>
                    Live monitor
                  </NavLink>
                  <NavLink to="/admin/statistics" className={navClass}>
                    Statistics
                  </NavLink>
                </>
              )}
            </nav>
          </div>
          <div className="flex items-center gap-3 text-sm">
            {user ? (
              <>
                <span className="text-slate-600">
                  {user.name} <span className="text-xs text-slate-400">({user.role.toLowerCase()})</span>
                </span>
                <button
                  type="button"
                  className="font-medium text-slate-600 hover:text-slate-900"
                  onClick={async () => {
                    await logout()
                    navigate('/login')
                  }}
                >
                  Log out
                </button>
              </>
            ) : (
              <>
                <Link to="/login" className="font-medium text-slate-600 hover:text-slate-900">Log in</Link>
                <Link to="/register" className="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-500">
                  Register
                </Link>
              </>
            )}
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
