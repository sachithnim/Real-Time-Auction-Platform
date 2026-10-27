import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { THEME_KEY } from '../../lib/theme'
import { ThemeToggle } from '../ThemeToggle'

afterEach(() => {
  window.localStorage.clear()
  document.documentElement.classList.remove('dark')
})

describe('<ThemeToggle>', () => {
  it('starts from the saved choice and applies it', () => {
    window.localStorage.setItem(THEME_KEY, 'dark')
    render(<ThemeToggle />)

    expect(screen.getByRole('button', { name: 'Theme: Dark. Switch to system.' })).toBeInTheDocument()
    expect(document.documentElement).toHaveClass('dark')
  })

  it('cycles light → dark → system and saves each choice', async () => {
    window.localStorage.setItem(THEME_KEY, 'light')
    render(<ThemeToggle />)
    expect(document.documentElement).not.toHaveClass('dark')

    await userEvent.click(screen.getByRole('button', { name: /Theme: Light/ }))
    expect(document.documentElement).toHaveClass('dark')
    expect(window.localStorage.getItem(THEME_KEY)).toBe('dark')

    // jsdom has no dark device setting, so "system" resolves to light here.
    await userEvent.click(screen.getByRole('button', { name: /Theme: Dark/ }))
    expect(screen.getByRole('button', { name: /Theme: System/ })).toBeInTheDocument()
    expect(document.documentElement).not.toHaveClass('dark')
    expect(window.localStorage.getItem(THEME_KEY)).toBeNull()
  })
})
