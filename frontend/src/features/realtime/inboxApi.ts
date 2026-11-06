import { api } from '../../lib/api'
import type { Meta } from '../../types/api'
import type { NoticeData } from './noticeText'

export interface InboxNotice {
  id: string
  type: string
  data: NoticeData
  read: boolean
  /** When it last changed. */
  at: string
}

export interface InboxPage {
  data: InboxNotice[]
  meta: Meta & { current_page: number; last_page: number; unread_count: number }
}

export const inboxKey = ['inbox'] as const

export async function fetchInbox() {
  const { data } = await api.get<InboxPage>('/api/me/notifications')
  return data
}

export async function markNoticeRead(id: string) {
  await api.post(`/api/me/notifications/${id}/read`)
}

export async function markAllNoticesRead() {
  await api.post('/api/me/notifications/read-all')
}
