/** The reader's choice; "system" follows the device setting. */
export type ThemePreference = 'light' | 'dark' | 'system'
export type Theme = 'light' | 'dark'

/** Also read by the inline script in index.html, which applies the theme before the first paint. */
export const THEME_KEY = 'livebid-theme'

export function readPreference(): ThemePreference {
  try {
    const stored = window.localStorage.getItem(THEME_KEY)
    return stored === 'light' || stored === 'dark' ? stored : 'system'
  } catch {
    // Storage can be blocked (private mode, strict settings): fall back to the device setting.
    return 'system'
  }
}

export function savePreference(preference: ThemePreference) {
  try {
    if (preference === 'system') {
      window.localStorage.removeItem(THEME_KEY)
    } else {
      window.localStorage.setItem(THEME_KEY, preference)
    }
  } catch {
    // Not saved, but the choice still applies for this visit.
  }
}

export function systemPrefersDark(): boolean {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
}

export function resolveTheme(preference: ThemePreference, systemDark: boolean): Theme {
  return preference === 'system' ? (systemDark ? 'dark' : 'light') : preference
}

export function applyTheme(theme: Theme) {
  document.documentElement.classList.toggle('dark', theme === 'dark')
}
