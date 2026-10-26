/** A WatchedAuctionReminder from the private user.{id} channel. */
export interface WatchReminderNotice {
  auction_id: number
  title: string
  kind: 'STARTING_SOON' | 'ENDING_SOON'
  at: string
  minutes_left: number
}

/** The pop-up text for a watchlist reminder. */
export function reminderToast(notice: WatchReminderNotice): { title: string; body: string } {
  const minutes = notice.minutes_left === 1 ? '1 minute' : `${notice.minutes_left} minutes`

  return notice.kind === 'STARTING_SOON'
    ? { title: 'Starting soon', body: `${notice.title} opens for bidding in ${minutes}.` }
    : { title: 'Ending soon', body: `${notice.title} closes in ${minutes}. Last chance to bid.` }
}
