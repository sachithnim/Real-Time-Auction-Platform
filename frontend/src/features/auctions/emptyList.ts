import type { AuctionListFilter } from './api'

export interface EmptyListMessage {
  icon: string
  title: string
  body: string
  /** The filter to clear to see more, if any: the search first, then the category, then the status tab. */
  clear: 'search' | 'category' | 'status' | null
}

/** What to say when the auction list is empty, depending on why it is empty. */
export function emptyListMessage(status: AuctionListFilter, category: string | null, search = ''): EmptyListMessage {
  if (search.trim()) {
    return { icon: '🔍', title: `No auctions match “${search.trim()}”`, body: 'Check the spelling, or try fewer or different words.', clear: 'search' }
  }
  if (category) {
    return { icon: '🏷️', title: 'Nothing in this category here yet', body: 'Try another category, or see every category at once.', clear: 'category' }
  }
  switch (status) {
    case 'WATCHING':
      return {
        icon: '☆',
        title: 'You are not watching any auctions yet',
        body: 'Tap ☆ Watch on an auction to keep it here, with a reminder before it starts and before it ends.',
        clear: 'status',
      }
    case 'LIVE':
      return { icon: '⏱️', title: 'No auctions are live right now', body: 'Upcoming auctions show when they start.', clear: 'status' }
    case 'SCHEDULED':
      return { icon: '📅', title: 'No upcoming auctions', body: 'New auctions appear here once they are scheduled.', clear: 'status' }
    case 'COMPLETED':
      return { icon: '🏁', title: 'No finished auctions yet', body: 'Results appear here when auctions end.', clear: 'status' }
    default:
      return { icon: '🔨', title: 'No auctions yet', body: 'Check back soon: new auctions appear here as soon as they are scheduled.', clear: null }
  }
}
