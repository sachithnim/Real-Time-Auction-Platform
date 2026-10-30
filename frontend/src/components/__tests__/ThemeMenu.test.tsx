import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { THEME_KEY } from '../../lib/theme'
import { ThemeMenu } from '../ThemeMenu'

afterEach(() => {
  window.localStorage.clear()
  document.documentElement.classList.remove('dark')
})

const trigger = () => screen.getByRole('button', { name: /^Theme:/ })

describe('<ThemeMenu>', () => {
  it('starts from the saved choice and applies it', () => {
    window.localStorage.setItem(THEME_KEY, 'dark')
    render(<ThemeMenu />)

    expect(trigger()).toHaveAccessibleName('Theme: Dark')
    expect(document.documentElement).toHaveClass('dark')
  })

  it('lists every theme and marks the current one', async () => {
    render(<ThemeMenu />)
    await userEvent.click(trigger())

    expect(screen.getByRole('menuitemradio', { name: 'Light' })).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByRole('menuitemradio', { name: 'Dark' })).toHaveAttribute('aria-checked', 'false')
    expect(screen.getByRole('menuitemradio', { name: 'System' })).toHaveAttribute('aria-checked', 'true')
  })

  it('applies and saves a choice, then closes', async () => {
    render(<ThemeMenu />)
    await userEvent.click(trigger())
    await userEvent.click(screen.getByRole('menuitemradio', { name: 'Dark' }))

    expect(document.documentElement).toHaveClass('dark')
    expect(window.localStorage.getItem(THEME_KEY)).toBe('dark')
    expect(screen.queryByRole('menu')).toBeNull()
    expect(trigger()).toHaveFocus()

    // jsdom has no dark device setting, so "system" resolves to light here.
    await userEvent.click(trigger())
    await userEvent.click(screen.getByRole('menuitemradio', { name: 'System' }))
    expect(document.documentElement).not.toHaveClass('dark')
    expect(window.localStorage.getItem(THEME_KEY)).toBeNull()
  })

  it('closes on Escape and on a click outside', async () => {
    render(
      <>
        <ThemeMenu />
        <p>Outside</p>
      </>,
    )
    await userEvent.click(trigger())
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('menu')).toBeNull()

    await userEvent.click(trigger())
    await userEvent.click(screen.getByText('Outside'))
    expect(screen.queryByRole('menu')).toBeNull()
  })
})
