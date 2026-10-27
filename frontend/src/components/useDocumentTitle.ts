import { useEffect } from 'react'

const APP = 'LiveBid'

/** Names the browser tab after the page, e.g. "Hand-Carved Kandyan Mask · LiveBid". */
export function useDocumentTitle(title: string | null | undefined) {
  useEffect(() => {
    document.title = title ? `${title} · ${APP}` : `${APP} · Real-Time Auctions`
  }, [title])
}
