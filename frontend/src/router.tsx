import { createBrowserRouter } from 'react-router'
import { Layout } from './components/Layout'
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
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
