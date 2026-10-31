import { createBrowserRouter } from 'react-router'
import { Layout } from './components/Layout'
import { AdminAuctionDetailPage } from './features/admin/AdminAuctionDetailPage'
import { AdminAuctionFormPage } from './features/admin/AdminAuctionFormPage'
import { AdminAuctionsPage } from './features/admin/AdminAuctionsPage'
import { AdminCategoriesPage } from './features/admin/AdminCategoriesPage'
import { AdminFlagsPage } from './features/admin/AdminFlagsPage'
import { AdminMonitorPage } from './features/admin/AdminMonitorPage'
import { AdminStatisticsPage } from './features/admin/AdminStatisticsPage'
import { RequireAuth } from './features/auth/guards'
import { AuctionDetailPage } from './features/auctions/AuctionDetailPage'
import { AuctionListPage } from './features/auctions/AuctionListPage'
import { LoginPage } from './features/auth/LoginPage'
import { RegisterPage } from './features/auth/RegisterPage'
import { NotFoundPage } from './components/NotFoundPage'
import { RouteError } from './components/RouteError'

export const router = createBrowserRouter([
  {
    element: <Layout />,
    errorElement: <RouteError />,
    children: [
      { path: '/', element: <AuctionListPage /> },
      { path: '/auctions/:id', element: <AuctionDetailPage /> },
      { path: '/admin/auctions', element: <RequireAuth role="ADMIN"><AdminAuctionsPage /></RequireAuth> },
      { path: '/admin/auctions/new', element: <RequireAuth role="ADMIN"><AdminAuctionFormPage /></RequireAuth> },
      { path: '/admin/auctions/:id', element: <RequireAuth role="ADMIN"><AdminAuctionDetailPage /></RequireAuth> },
      { path: '/admin/auctions/:id/edit', element: <RequireAuth role="ADMIN"><AdminAuctionFormPage /></RequireAuth> },
      { path: '/admin/statistics', element: <RequireAuth role="ADMIN"><AdminStatisticsPage /></RequireAuth> },
      { path: '/admin/monitor', element: <RequireAuth role="ADMIN"><AdminMonitorPage /></RequireAuth> },
      { path: '/admin/flags', element: <RequireAuth role="ADMIN"><AdminFlagsPage /></RequireAuth> },
      { path: '/admin/categories', element: <RequireAuth role="ADMIN"><AdminCategoriesPage /></RequireAuth> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
  // Full-screen pages with their own frame (AuthLayout), outside the main header.
  { path: '/login', element: <LoginPage />, errorElement: <RouteError /> },
  { path: '/register', element: <RegisterPage />, errorElement: <RouteError /> },
])
