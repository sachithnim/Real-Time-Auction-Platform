import { createBrowserRouter } from 'react-router'
import { Layout } from './components/Layout'
import { AdminAuctionDetailPage } from './features/admin/AdminAuctionDetailPage'
import { AdminAuctionFormPage } from './features/admin/AdminAuctionFormPage'
import { AdminAuctionsPage } from './features/admin/AdminAuctionsPage'
import { AdminStatisticsPage } from './features/admin/AdminStatisticsPage'
import { RequireAuth } from './features/auth/guards'
import { AuctionDetailPage } from './features/auctions/AuctionDetailPage'
import { AuctionListPage } from './features/auctions/AuctionListPage'
import { LoginPage } from './features/auth/LoginPage'
import { RegisterPage } from './features/auth/RegisterPage'
import { NotFoundPage } from './components/NotFoundPage'

export const router = createBrowserRouter([
  {
    element: <Layout />,
    children: [
      { path: '/', element: <AuctionListPage /> },
      { path: '/auctions/:id', element: <AuctionDetailPage /> },
      { path: '/admin/auctions', element: <RequireAuth role="ADMIN"><AdminAuctionsPage /></RequireAuth> },
      { path: '/admin/auctions/new', element: <RequireAuth role="ADMIN"><AdminAuctionFormPage /></RequireAuth> },
      { path: '/admin/auctions/:id', element: <RequireAuth role="ADMIN"><AdminAuctionDetailPage /></RequireAuth> },
      { path: '/admin/auctions/:id/edit', element: <RequireAuth role="ADMIN"><AdminAuctionFormPage /></RequireAuth> },
      { path: '/admin/statistics', element: <RequireAuth role="ADMIN"><AdminStatisticsPage /></RequireAuth> },
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
