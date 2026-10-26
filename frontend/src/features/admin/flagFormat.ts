import type { BidFlag } from '../../types/api'
import { rejectionReason } from './monitor'

export interface FlagDescription {
  title: string
  why: string
  evidence: string
}

const num = (value: unknown) => (typeof value === 'number' ? value : 0)

/** A flag in plain words: what was seen, why it matters, and the numbers behind it. */
export function describeFlag(flag: BidFlag): FlagDescription {
  const d = flag.details

  switch (flag.rule) {
    case 'RAPID_BIDDING':
      return {
        title: 'Rapid bidding',
        why: 'Many manual bids in a short time can mean scripted or bot bidding.',
        evidence: `${num(d.bids)} manual bids within ${num(d.window_seconds)} seconds`,
      }
    case 'MANY_REJECTIONS': {
      const reasons = Object.entries((d.reasons ?? {}) as Record<string, number>)
        .map(([code, count]) => `${rejectionReason(code)} ×${count}`)
        .join(', ')
      return {
        title: 'Many rejected bids',
        why: 'Repeated refused bids can mean someone probing the minimum, or a misbehaving client.',
        evidence: `${num(d.rejections)} rejected bids within ${num(d.window_minutes)} minutes${reasons ? ` (${reasons})` : ''}`,
      }
    }
    case 'SHARED_IP':
      return {
        title: 'Shared IP address',
        why: 'Two accounts bidding from the same network can mean one person using a second account to push the price up.',
        evidence: `Same IP: ${((d.ip_addresses ?? []) as string[]).join(', ')}`,
      }
    case 'ALTERNATING_LEADERS':
      return {
        title: 'Alternating leaders',
        why: 'Only these two bidders, trading the lead again and again, can mean coordinated price inflation.',
        evidence: `${num(d.lead_changes)} lead changes between ${num(d.bidders)} bidders`,
      }
  }
}
