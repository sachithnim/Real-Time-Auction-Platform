import { describe, expect, it } from 'vitest'
import { noticeText } from '../noticeText'

const base = { auction_id: 7, title: 'Brass Telescope' }

describe('noticeText', () => {
  it('words every kind of notice the same way as the pop-up', () => {
    expect(noticeText('Outbid', { ...base, current_price: 15_500, min_next_bid: 16_000 })).toEqual({
      tone: 'warning',
      title: 'Outbid on Brass Telescope',
      body: 'Price is now Rs. 15,500. Bid Rs. 16,000 to lead again.',
    })
    expect(noticeText('AutoBidMaxReached', { ...base, max_amount: 15_000 }).body).toBe('Brass Telescope: your maximum of Rs. 15,000 has been passed.')
    expect(noticeText('AuctionWon', { ...base, final_price: 20_000 })).toMatchObject({ tone: 'success', title: 'You won!', body: 'Brass Telescope for Rs. 20,000.' })
    expect(noticeText('WatchedAuctionReminder', { ...base, kind: 'ENDING_SOON', minutes_left: 5, at: '' }).title).toBe('Ending soon')
    expect(noticeText('PaymentReceived', { ...base, amount: 20_000, reference: 'PAY-1' }).body).toBe('Brass Telescope: Rs. 20,000 paid, receipt PAY-1.')
  })

  it('still says something for a kind it does not know', () => {
    expect(noticeText('SomethingNew', base)).toEqual({ tone: 'info', title: 'Brass Telescope', body: 'There is an update on this auction.' })
  })
})
