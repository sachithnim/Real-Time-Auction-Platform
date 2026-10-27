import { formatRs } from '../../lib/money'
import type { AuctionEvent } from '../../types/api'

/** Names of the users an audit page refers to, keyed by user id (from meta.users). */
export type AuditUsers = Record<string, { name: string; email: string }>

export type AuditTone = 'neutral' | 'bid' | 'warning' | 'danger' | 'success'

/** One audit event, ready to show to a person. */
export interface AuditView {
  title: string
  summary: string
  details: { label: string; value: string }[]
  tone: AuditTone
}

const titles: Record<string, string> = {
  AUCTION_CREATED: 'Auction created',
  AUCTION_UPDATED: 'Auction edited',
  AUCTION_SCHEDULED: 'Auction scheduled',
  AUCTION_STARTED: 'Auction started',
  BID_PLACED: 'Bid placed',
  BID_REJECTED: 'Bid rejected',
  AUTO_BID_CONFIGURED: 'Automatic bid set',
  AUTO_BID_PLACED: 'Automatic bid placed',
  AUTO_BID_MAX_REACHED: 'Maximum reached',
  LEADER_CHANGED: 'Leader changed',
  AUCTION_EXTENDED: 'Auction extended',
  AUCTION_ENDED: 'Auction ended',
  WINNER_SELECTED: 'Winner selected',
  RESERVE_NOT_MET: 'Reserve not met',
  AUCTION_CANCELLED: 'Auction cancelled',
  PAYMENT_SUCCEEDED: 'Payment received',
  PAYMENT_FAILED: 'Payment declined',
}

/** "AUCTION_UPDATED" → "Auction edited"; unknown types → "Something happened". */
export function eventTitle(type: string): string {
  return titles[type] ?? sentenceCase(type)
}

const fieldLabels: Record<string, string> = {
  title: 'Title',
  description: 'Description',
  category_id: 'Category',
  starting_price: 'Starting price',
  reserve_price: 'Reserve price',
  min_increment: 'Minimum increment',
  start_at: 'Starts',
  end_at: 'Ends',
  anti_snipe_seconds: 'Anti-sniping window',
  extension_seconds: 'Extension',
  max_extensions: 'Maximum extensions',
}

const reasons: Record<string, string> = {
  BID_TOO_LOW: 'below the minimum bid',
  MAX_AUTO_BID_REACHED: 'maximum too low',
  INVALID_BID: 'not a valid amount',
  AUCTION_NOT_STARTED: 'the auction had not started',
  AUCTION_ENDED: 'the auction had ended',
  AUCTION_NOT_LIVE: 'the auction was not open',
}

const paymentReasons: Record<string, string> = {
  CARD_DECLINED: 'the card was declined',
  INSUFFICIENT_FUNDS: 'insufficient funds',
}

/** "VISA •••• 4242" from a payment event's card fields. */
function cardLabel(p: Record<string, unknown>): string {
  return `${String(p.card_brand ?? 'card')} •••• ${String(p.card_last4 ?? '????')}`
}

const statusWords: Record<string, string> = {
  DRAFT: 'a draft',
  SCHEDULED: 'scheduled',
  LIVE: 'live',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
}

const resultWords: Record<string, string> = {
  SOLD: 'sold',
  RESERVE_NOT_MET: 'not sold (reserve not met)',
  NO_BIDS: 'no bids',
}

function sentenceCase(code: string): string {
  const words = code.toLowerCase().replace(/_/g, ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

function seconds(value: number): string {
  if (value === 0) {
    return 'off'
  }
  return value % 60 === 0 ? `${value / 60} min` : `${value} s`
}

function dateTime(value: unknown): string {
  return typeof value === 'string' && !Number.isNaN(Date.parse(value)) ? new Date(value).toLocaleString() : '—'
}

/** Formats one auction field by its meaning: money, time, duration or text. */
export function fieldValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return field === 'reserve_price' ? 'none' : '—'
  }
  if (field.endsWith('_price') || field === 'min_increment') {
    return formatRs(value as number)
  }
  if (field.endsWith('_at')) {
    return dateTime(value)
  }
  if (field.endsWith('_seconds')) {
    return seconds(value as number)
  }
  return String(value)
}

function fieldLabel(field: string): string {
  return fieldLabels[field] ?? sentenceCase(field)
}

/** Describes an event as a heading, one plain sentence and optional details. */
export function describeEvent(event: AuctionEvent, users: AuditUsers = {}): AuditView {
  const p = event.payload as Record<string, unknown>
  const who = (key: string, fallback = 'nobody') => {
    const id = p[key]
    if (typeof id !== 'number') {
      return fallback
    }
    return users[String(id)]?.name ?? `User #${id}`
  }
  const rs = (key: string) => formatRs(p[key] as number | null)

  const view = (summary: string, tone: AuditTone, details: AuditView['details'] = []): AuditView => ({
    title: eventTitle(event.type),
    summary,
    details,
    tone,
  })

  switch (event.type) {
    case 'AUCTION_CREATED':
      return view(
        `Created as a draft "${String(p.title ?? '')}".`,
        'neutral',
        Object.keys(fieldLabels)
          .filter((field) => field in p && field !== 'title' && field !== 'description')
          .map((field) => ({ label: fieldLabel(field), value: fieldValue(field, p[field]) })),
      )

    case 'AUCTION_UPDATED': {
      const changes = (p.changes ?? {}) as Record<string, { from?: unknown; to?: unknown }>
      const fields = Object.keys(changes)
      return view(
        `Changed ${fields.map((f) => fieldLabel(f).toLowerCase()).join(', ') || 'nothing'}.`,
        'neutral',
        fields.map((field) => ({
          label: fieldLabel(field),
          value: `${fieldValue(field, changes[field]?.from)} → ${fieldValue(field, changes[field]?.to)}`,
        })),
      )
    }

    case 'AUCTION_SCHEDULED':
      return view(`Scheduled to run from ${dateTime(p.start_at)} to ${dateTime(p.end_at)}.`, 'neutral')

    case 'AUCTION_STARTED':
      return view(`Opened for bidding. Ends ${dateTime(p.end_at)}.`, 'success')

    case 'BID_PLACED':
      return view(`${who('bidder_id')} bid ${rs('amount')}. Price ${rs('previous_price')} → ${rs('new_price')}.`, 'bid')

    case 'AUTO_BID_PLACED':
      return view(`Automatic bid of ${rs('amount')} for ${who('bidder_id')}. Price ${rs('previous_price')} → ${rs('new_price')}.`, 'bid')

    case 'BID_REJECTED': {
      const what = p.action === 'AUTO_BID' ? 'Maximum' : 'Bid'
      const reason = reasons[String(p.reason)] ?? sentenceCase(String(p.reason ?? 'unknown')).toLowerCase()
      const details = (p.details ?? {}) as Record<string, unknown>
      const minimum = typeof details.min_next_bid === 'number' ? ` (minimum was ${formatRs(details.min_next_bid)})` : ''
      return view(`${what} of ${rs('amount')} by ${who('bidder_id', 'a bidder')} was rejected: ${reason}${minimum}.`, 'danger')
    }

    case 'AUTO_BID_CONFIGURED': {
      const raised = typeof p.previous_max_amount === 'number' ? `, raised from ${rs('previous_max_amount')}` : ''
      return view(`${who('user_id', 'A bidder')} set an automatic maximum of ${rs('max_amount')}${raised}.`, 'bid')
    }

    case 'AUTO_BID_MAX_REACHED':
      return view(`${who('user_id', 'A bidder')}'s maximum of ${rs('max_amount')} was passed. Price is ${rs('price')}.`, 'warning')

    case 'LEADER_CHANGED':
      return view(`Lead passed from ${who('previous_leader_id')} to ${who('new_leader_id')} at ${rs('price')}.`, 'bid')

    case 'AUCTION_EXTENDED':
      return view(
        `A late bid extended the end from ${dateTime(p.previous_end_at)} to ${dateTime(p.new_end_at)} ` +
          `(extension ${String(p.extensions_count ?? '?')} of ${String(p.max_extensions ?? '?')}).`,
        'warning',
      )

    case 'AUCTION_ENDED': {
      const result = resultWords[String(p.result)] ?? sentenceCase(String(p.result ?? ''))
      const highest = typeof p.highest_bid === 'number' ? ` Highest bid ${rs('highest_bid')} by ${who('highest_bidder_id')}.` : ''
      return view(`Closed: ${result}.${highest} ${String(p.bids_count ?? 0)} bids.`, 'success')
    }

    case 'WINNER_SELECTED':
      return view(`${who('winner_id', 'A bidder')} won at ${rs('final_price')}.`, 'success', [
        { label: 'Reserve price', value: fieldValue('reserve_price', p.reserve_price) },
      ])

    case 'RESERVE_NOT_MET':
      return view(`Highest bid ${rs('highest_bid')} was below the reserve of ${rs('reserve_price')}, so the item was not sold.`, 'danger')

    case 'AUCTION_CANCELLED': {
      const reason = typeof p.reason === 'string' && p.reason.trim() !== '' ? ` Reason: "${p.reason}".` : ' No reason given.'
      const while_ = statusWords[String(p.from_status)] ?? String(p.from_status ?? '').toLowerCase()
      return view(`Cancelled while it was ${while_}.${reason}`, 'danger')
    }

    case 'PAYMENT_SUCCEEDED':
      return view(`${event.actor?.name ?? 'The winner'} paid ${rs('amount')} by ${cardLabel(p)}.`, 'success', [
        { label: 'Receipt', value: String(p.reference ?? '—') },
        { label: 'Attempt', value: String(p.attempt ?? 1) },
      ])

    case 'PAYMENT_FAILED': {
      const reason = paymentReasons[String(p.reason)] ?? sentenceCase(String(p.reason ?? 'declined')).toLowerCase()
      return view(`${event.actor?.name ?? 'The winner'}'s payment of ${rs('amount')} by ${cardLabel(p)} was declined: ${reason}.`, 'danger')
    }

    default:
      return view(
        '',
        'neutral',
        Object.entries(p).map(([key, value]) => ({ label: fieldLabel(key), value: fieldValue(key, value) })),
      )
  }
}
