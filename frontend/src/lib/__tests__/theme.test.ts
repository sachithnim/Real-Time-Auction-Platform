import { afterEach, describe, expect, it } from 'vitest'
import { applyTheme, readPreference, resolveTheme, savePreference, THEME_KEY } from '../theme'

afterEach(() => {
  window.localStorage.clear()
  document.documentElement.classList.remove('dark')
})

describe('theme', () => {
  it('follows the device when the reader chose "system"', () => {
    expect(resolveTheme('system', true)).toBe('dark')
    expect(resolveTheme('system', false)).toBe('light')
  })

  it('keeps an explicit choice whatever the device says', () => {
    expect(resolveTheme('light', true)).toBe('light')
    expect(resolveTheme('dark', false)).toBe('dark')
  })

  it('remembers light or dark, and forgets the choice for system', () => {
    expect(readPreference()).toBe('system')

    savePreference('dark')
    expect(window.localStorage.getItem(THEME_KEY)).toBe('dark')
    expect(readPreference()).toBe('dark')

    savePreference('system')
    expect(window.localStorage.getItem(THEME_KEY)).toBeNull()
    expect(readPreference()).toBe('system')
  })

  it('ignores a stored value it does not know', () => {
    window.localStorage.setItem(THEME_KEY, 'purple')

    expect(readPreference()).toBe('system')
  })

  it('switches the page with the "dark" class on <html>', () => {
    applyTheme('dark')
    expect(document.documentElement).toHaveClass('dark')

    applyTheme('light')
    expect(document.documentElement).not.toHaveClass('dark')
  })
})
