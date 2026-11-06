import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { usePopover } from '../../components/usePopover'
import { useNow } from '../../components/useNow'
import { formatRelative } from '../../lib/time'
import { fetchInbox, inboxKey, markAllNoticesRead, markNoticeRead, type InboxNotice, type InboxPage } from './inboxApi'
import { noticeText, type NoticeText } from './noticeText'

const dots: Record<NoticeText['tone'], string> = {
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  info: 'bg-sky-500',
}

/**
 * The bell in the header: every private notification, kept, with an unread
 * count. Pop-ups disappear after a few seconds; this is where a missed one waits.
 */
export function NotificationBell() {
  const { open, rootRef, buttonRef, menuId, toggle, close, buttonProps } = usePopover()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const now = useNow(60_000)
  const inbox = useQuery({ queryKey: inboxKey, queryFn: fetchInbox })
  const unread = inbox.data?.meta.unread_count ?? 0

  // Read at once on screen; a failure just brings the server's state back.
  const optimistic = (change: (page: InboxPage) => InboxPage) => {
    queryClient.setQueryData<InboxPage>(inboxKey, (page) => (page ? change(page) : page))
  }
  const markOne = useMutation({
    mutationFn: (notice: InboxNotice) => markNoticeRead(notice.id),
    onMutate: (notice) =>
      optimistic((page) => ({
        ...page,
        data: page.data.map((n) => (n.id === notice.id ? { ...n, read: true } : n)),
        meta: { ...page.meta, unread_count: Math.max(0, page.meta.unread_count - (notice.read ? 0 : 1)) },
      })),
    onSettled: () => queryClient.invalidateQueries({ queryKey: inboxKey }),
  })
  const markAll = useMutation({
    mutationFn: markAllNoticesRead,
    onMutate: () => optimistic((page) => ({ ...page, data: page.data.map((n) => ({ ...n, read: true })), meta: { ...page.meta, unread_count: 0 } })),
    onSettled: () => queryClient.invalidateQueries({ queryKey: inboxKey }),
  })

  const openNotice = (notice: InboxNotice) => {
    if (!notice.read) {
      markOne.mutate(notice)
    }
    close()
    navigate(`/auctions/${notice.data.auction_id}`)
  }

  const label = unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'

  return (
    <div ref={rootRef} className="relative">
      <button
        ref={buttonRef}
        {...buttonProps}
        onClick={toggle}
        aria-label={label}
        className="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900"
      >
        <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
          <path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0" />
        </svg>
        {unread > 0 && (
          <span className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-[#ffffff] tabular-nums" aria-hidden>
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div id={menuId} role="menu" aria-label="Notifications" className="absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-lg bg-white shadow-lg ring-1 ring-slate-200">
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
            <p className="text-sm font-semibold text-slate-900">Notifications</p>
            {unread > 0 && (
              <button type="button" onClick={() => markAll.mutate()} className="text-xs font-medium text-indigo-700 hover:underline">
                Mark all as read
              </button>
            )}
          </div>

          {inbox.isPending && <p className="px-4 py-6 text-center text-sm text-slate-500">Loading…</p>}
          {inbox.isError && <p className="px-4 py-6 text-center text-sm text-slate-500">Could not load notifications.</p>}
          {inbox.data && inbox.data.data.length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-slate-500">Nothing yet. You’ll see it here when you’re outbid, win, or a watched auction is about to start or end.</p>
          )}

          {inbox.data && inbox.data.data.length > 0 && (
            <ul className="max-h-[min(26rem,70vh)] divide-y divide-slate-100 overflow-y-auto">
              {inbox.data.data.map((notice) => {
                const text = noticeText(notice.type, notice.data)
                const when = formatRelative(notice.at, now)
                return (
                  <li key={notice.id}>
                    <button
                      type="button"
                      role="menuitem"
                      onClick={() => openNotice(notice)}
                      // Spoken as one clear sentence; the visible lines would otherwise run together.
                      aria-label={`${notice.read ? '' : 'Unread: '}${sentence(text.title)} ${text.body} ${when}.`}
                      className={`flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50 ${notice.read ? '' : 'bg-indigo-50/60'}`}
                    >
                      <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${notice.read ? 'bg-transparent' : dots[text.tone]}`} aria-hidden />
                      <span className="min-w-0 flex-1">
                        <span className={`block text-sm ${notice.read ? 'text-slate-700' : 'font-semibold text-slate-900'}`}>
                          {text.title}
                        </span>
                        <span className="block text-sm text-slate-600">{text.body}</span>
                        <time dateTime={notice.at} className="mt-0.5 block text-xs text-slate-500">
                          {when}
                        </time>
                      </span>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

/** Ends a title with a full stop unless it already ends with punctuation ("You won!"). */
function sentence(text: string): string {
  return /[.!?]$/.test(text) ? text : `${text}.`
}
