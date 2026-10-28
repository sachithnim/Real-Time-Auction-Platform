import type { KeyboardEvent } from 'react'

/**
 * Keyboard support for a role="tablist" of buttons, as screen-reader users
 * expect: Left/Right (wrapping), Home and End move to a tab and select it.
 * Use with tabIndex={selected ? 0 : -1} on each tab, so Tab enters the list
 * once and then moves on to the content.
 */
export function onTabListKeyDown(event: KeyboardEvent<HTMLElement>) {
  const tabs = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('[role="tab"]:not([disabled])'))
  const current = tabs.indexOf(document.activeElement as HTMLElement)
  if (current === -1) {
    return
  }

  const target = {
    ArrowRight: (current + 1) % tabs.length,
    ArrowLeft: (current - 1 + tabs.length) % tabs.length,
    Home: 0,
    End: tabs.length - 1,
  }[event.key]

  if (target === undefined) {
    return
  }
  event.preventDefault()
  tabs[target].focus()
  tabs[target].click()
}
