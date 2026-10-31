import { Link } from 'react-router'
import { Logo } from './Logo'

// Read once when the app loads: a footer year does not need to tick over at midnight.
const year = new Date().getFullYear()

/** A quiet sign-off: the brand on the left, the copyright centred below. Navigation lives in the header and the tabs. */
export function Footer() {
  return (
    <footer className="mt-16 border-t border-slate-200 bg-white">
      <div className="mx-auto max-w-6xl space-y-2 px-4 py-8">
        <Link to="/" aria-label="LiveBid home" className="inline-block">
          <Logo />
        </Link>
        <p className="max-w-md text-sm text-slate-500">Real-time auctions with private maximum bids and fair finishes.</p>
      </div>
      <div className="border-t border-slate-100">
        <p className="px-4 py-4 text-center text-xs text-slate-500">© {year} LiveBid. All rights reserved.</p>
      </div>
    </footer>
  )
}
