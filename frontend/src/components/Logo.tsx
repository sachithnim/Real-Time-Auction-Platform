/**
 * The LiveBid emblem with the name beside it. The name is text, not part of the
 * image, so it stays sharp and follows the light / dark theme.
 * "onDark" is for the always-dark brand panel on the log-in and register pages.
 */
export function Logo({ size = 'md', tone = 'default' }: { size?: 'md' | 'lg'; tone?: 'default' | 'onDark' }) {
  const image = size === 'lg' ? 'h-10 w-10' : 'h-8 w-8'
  const text = size === 'lg' ? 'text-2xl' : 'text-lg'
  // orange-400/500 are not remapped for dark mode, so "Bid" keeps the emblem's orange in both themes.
  const live = tone === 'onDark' ? 'text-[#ffffff]' : 'text-indigo-700'
  const bid = tone === 'onDark' ? 'text-orange-400' : 'text-orange-500'

  return (
    <span className="flex items-center gap-2">
      <img src="/logo.png" alt="" className={`${image} shrink-0 object-contain`} />
      <span className={`${text} font-bold tracking-tight`}>
        <span className={live}>Live</span>
        <span className={bid}>Bid</span>
      </span>
    </span>
  )
}
