import { useEffect, useState } from 'react'
import { applyTheme, nextPreference, readPreference, resolveTheme, savePreference, systemPrefersDark, type ThemePreference } from '../lib/theme'

const labels: Record<ThemePreference, string> = { light: 'Light', dark: 'Dark', system: 'System' }

/** Switches light / dark / system. "System" also follows the device if it changes while the page is open. */
export function ThemeToggle({ className = '' }: { className?: string }) {
  const [preference, setPreference] = useState<ThemePreference>(readPreference)

  useEffect(() => {
    applyTheme(resolveTheme(preference, systemPrefersDark()))
    if (preference !== 'system' || !window.matchMedia) {
      return
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    const follow = () => applyTheme(resolveTheme('system', media.matches))
    media.addEventListener('change', follow)
    return () => media.removeEventListener('change', follow)
  }, [preference])

  const next = nextPreference(preference)

  return (
    <button
      type="button"
      onClick={() => {
        savePreference(next)
        setPreference(next)
      }}
      aria-label={`Theme: ${labels[preference]}. Switch to ${labels[next].toLowerCase()}.`}
      title={`Theme: ${labels[preference]}`}
      className={`inline-flex items-center rounded-md p-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 ${className}`}
    >
      <ThemeIcon preference={preference} />
    </button>
  )
}

function ThemeIcon({ preference }: { preference: ThemePreference }) {
  const common = { viewBox: '0 0 24 24', className: 'h-5 w-5', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true }

  if (preference === 'light') {
    return (
      <svg {...common}>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
      </svg>
    )
  }
  if (preference === 'dark') {
    return (
      <svg {...common}>
        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
      </svg>
    )
  }
  return (
    <svg {...common}>
      <rect x="3" y="4" width="18" height="12" rx="2" />
      <path d="M8 20h8M12 16v4" />
    </svg>
  )
}
