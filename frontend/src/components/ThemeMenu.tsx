import { useEffect, useState } from 'react'
import { applyTheme, readPreference, resolveTheme, savePreference, systemPrefersDark, type ThemePreference } from '../lib/theme'
import { usePopover } from './usePopover'

const options: { value: ThemePreference; label: string }[] = [
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
  { value: 'system', label: 'System' },
]

/**
 * A small menu to pick light, dark or system theme. Every choice is visible at once,
 * so nobody has to click through a cycle to find the one they want.
 * "System" also follows the device if it changes while the page is open.
 */
export function ThemeMenu({ className = '' }: { className?: string }) {
  const [preference, setPreference] = useState<ThemePreference>(readPreference)
  const { open, rootRef, buttonRef, menuId, toggle, close, buttonProps } = usePopover()

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

  const choose = (value: ThemePreference) => {
    savePreference(value)
    setPreference(value)
    close(true)
  }

  const current = options.find((option) => option.value === preference)!

  return (
    <div ref={rootRef} className={`relative ${className}`}>
      <button
        ref={buttonRef}
        {...buttonProps}
        onClick={toggle}
        aria-label={`Theme: ${current.label}`}
        title={`Theme: ${current.label}`}
        className="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-100 hover:text-slate-900"
      >
        <ThemeIcon preference={preference} />
      </button>
      {open && (
        <div
          id={menuId}
          role="menu"
          aria-label="Theme"
          className="absolute right-0 z-40 mt-2 w-40 overflow-hidden rounded-lg bg-white p-1 shadow-lg ring-1 ring-slate-200"
        >
          {options.map((option) => (
            <button
              key={option.value}
              type="button"
              role="menuitemradio"
              aria-checked={option.value === preference}
              onClick={() => choose(option.value)}
              className={`flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm ${
                option.value === preference ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-700 hover:bg-slate-100'
              }`}
            >
              <ThemeIcon preference={option.value} />
              <span className="flex-1">{option.label}</span>
              {option.value === preference && (
                <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2.5} aria-hidden>
                  <path d="M5 12l5 5L20 7" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function ThemeIcon({ preference }: { preference: ThemePreference }) {
  const common = { viewBox: '0 0 24 24', className: 'h-[18px] w-[18px]', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true }

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
