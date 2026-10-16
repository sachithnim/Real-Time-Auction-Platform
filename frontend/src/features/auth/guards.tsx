import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { PageLoader } from '../../components/ui'
import type { Role } from '../../types/api'
import { useAuth } from './useAuth'

/** UI convenience only: the API enforces every permission itself. */
export function RequireAuth({ children, role }: { children: ReactNode; role?: Role }) {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return <PageLoader />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (role && user.role !== role) {
    return <Navigate to="/" replace />
  }

  return children
}
