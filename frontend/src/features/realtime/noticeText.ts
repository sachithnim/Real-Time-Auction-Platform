import { formatRs } from '../../lib/money'
import { reminderToast, type WatchReminderNotice } from './reminders'

/** The private notifications a bidder receives, by their event name. */
export type NoticeType = 'Outbid' | 'AutoBidMaxReached' | 'AuctionWon' | 'WatchedAuctionReminder' | 'PaymentReceived'

/** What a notification carries: always the auction, plus whatever that kind needs. */
export interface NoticeData {
  auction_id: number
  title: string
  current_price?: number
  min_next_bid?: number
  max_amount?: number
  final_price?: number
  amount?: number
  reference?: string
  kind?: WatchReminderNotice['kind']
  at?: string
  minutes_left?: number
}

export interface NoticeText {
  title: string
  body: string
  tone: 'success' | 'warning' | 'info'
}

/**
 * The words for a notification, shared by the pop-up and the bell so both say
 * the same thing.
 */
export function noticeText(type: string, n: NoticeData): NoticeText {
  switch (type) {
    case 'Outbid':
      return {
        tone: 'warning',
        title: `Outbid on ${n.title}`,
        body: `Price is now ${formatRs(n.current_price)}. Bid ${formatRs(n.min_next_bid)} to lead again.`,
      }
    case 'AutoBidMaxReached':
      return { tone: 'warning', title: 'Your maximum was reached', body: `${n.title}: your maximum of ${formatRs(n.max_amount)} has been passed.` }
    case 'AuctionWon':
      return { tone: 'success', title: 'You won!', body: `${n.title} for ${formatRs(n.final_price)}.` }
    case 'WatchedAuctionReminder':
      return { tone: 'info', ...reminderToast(n as WatchReminderNotice) }
    case 'PaymentReceived':
      return { tone: 'success', title: 'Payment received', body: `${n.title}: ${formatRs(n.amount)} paid${n.reference ? `, receipt ${n.reference}` : ''}.` }
    default:
      return { tone: 'info', title: n.title, body: 'There is an update on this auction.' }
  }
}
