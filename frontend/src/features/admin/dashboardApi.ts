import { api } from '../../lib/api'

interface DashboardAuction {
  id: number
  title: string
  bids_count: number
  images_count: number
}

export interface AdminDashboard {
  auctions: { draft: number; scheduled: number; live: number }
  last_24_hours: { bids: number; active_bidders: number; new_bidders: number; auctions_ended: number; sold_value: number }
  attention: { open_flags: number; unpaid_wins: number; unpaid_sold: number }
  ending_soon: (DashboardAuction & { current_price: number | null; end_at: string; leader: string | null })[]
  starting_soon: (DashboardAuction & { starting_price: number; start_at: string })[]
  drafts: (DashboardAuction & { updated_at: string; start_at: string })[]
  recent_activity: {
    id: number
    type: string
    auction: { id: number; title: string | null }
    actor: string | null
    amount: number | null
    created_at: string
  }[]
}

export const dashboardKey = ['admin', 'dashboard'] as const

export async function fetchDashboard() {
  const { data } = await api.get<{ data: AdminDashboard }>('/api/admin/dashboard')
  return data.data
}
