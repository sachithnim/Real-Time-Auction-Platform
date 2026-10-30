import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ThemeMenu } from '../../components/ThemeMenu'

const highlights = [
  { title: 'Live to the second', text: 'Every bid, price change and extension reaches your screen the moment it happens.' },
  { title: 'Bid while you are away', text: 'Set a private maximum and the system bids only as much as needed to keep you ahead.' },
  { title: 'Fair finishes', text: 'A bid in the final minutes extends the auction, so no one wins by sniping.' },
]

/**
 * Full-screen frame for the log-in and register pages: a brand panel on wide
 * screens, the form on the right, and the theme menu in the top corner.
 */
export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle: string; children: ReactNode; footer: ReactNode }) {
  return (
    <div className="flex min-h-screen">
      {/* indigo-950 / violet-950 are not remapped for dark mode, so the panel looks the same in both themes. */}
      <aside className="relative hidden w-[44%] max-w-xl flex-col justify-between overflow-hidden bg-linear-to-br from-indigo-950 via-indigo-950 to-violet-950 p-10 text-[#eef2ff] lg:flex">
        <div className="pointer-events-none absolute -top-24 -right-24 h-80 w-80 rounded-full bg-[#6366f1]/30 blur-3xl" aria-hidden />
        <div className="pointer-events-none absolute -bottom-32 -left-20 h-96 w-96 rounded-full bg-[#8b5cf6]/25 blur-3xl" aria-hidden />

        <Link to="/" className="relative flex items-center gap-2 text-xl font-bold tracking-tight">
          <LogoMark />
          LiveBid
        </Link>

        <div className="relative space-y-8">
          <h2 className="text-4xl leading-tight font-semibold tracking-tight">
            Real-time auctions,
            <br />
            <span className="text-[#a5b4fc]">decided fairly.</span>
          </h2>
          <ul className="space-y-5">
            {highlights.map((item) => (
              <li key={item.title} className="flex gap-3">
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#ffffff]/10 ring-1 ring-[#ffffff]/20">
                  <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={3} aria-hidden>
                    <path d="M5 12l5 5L20 7" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                </span>
                <div>
                  <p className="font-medium">{item.title}</p>
                  <p className="text-sm text-[#c7d2fe]">{item.text}</p>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-xs text-[#a5b4fc]">Times shown follow the server clock, so every bidder sees the same countdown.</p>
      </aside>

      <div className="flex flex-1 flex-col">
        <header className="flex items-center justify-between px-4 py-4 sm:px-8">
          <Link to="/" className="flex items-center gap-2 text-lg font-bold text-indigo-700 lg:invisible">
            <LogoMark />
            LiveBid
          </Link>
          <div className="flex items-center gap-3">
            <Link to="/" className="text-sm font-medium text-slate-600 hover:text-slate-900">
              Browse auctions
            </Link>
            <ThemeMenu />
          </div>
        </header>

        <main id="main" className="flex flex-1 items-center justify-center px-4 pt-4 pb-12 sm:px-8">
          <div className="w-full max-w-md">
            <div className="mb-8">
              <h1 className="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{title}</h1>
              <p className="mt-2 text-sm text-slate-600">{subtitle}</p>
            </div>
            {children}
            <div className="mt-8 text-center text-sm text-slate-600">{footer}</div>
          </div>
        </main>
      </div>
    </div>
  )
}

function LogoMark() {
  return (
    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[#6366f1] text-[#ffffff] shadow-sm" aria-hidden>
      <svg viewBox="0 0 24 24" className="h-4.5 w-4.5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        <path d="M14 4l6 6M11.5 6.5l6 6M13 5l-7 7 4 4 7-7M8 14l-5 5M4 21h9" />
      </svg>
    </span>
  )
}
