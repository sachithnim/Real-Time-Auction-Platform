import { useEffect, useId, useRef, useState } from 'react'

/**
 * Open / close state for a button with a dropdown menu. The menu closes on a click
 * outside or on Escape; Escape (and close(true)) hand focus back to the button.
 */
export function usePopover() {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) {
      return
    }
    const onPointer = (e: PointerEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  return {
    open,
    rootRef,
    buttonRef,
    menuId,
    toggle: () => setOpen((value) => !value),
    close: (refocus = false) => {
      setOpen(false)
      if (refocus) {
        buttonRef.current?.focus()
      }
    },
    /** Spread on the trigger button, together with ref={buttonRef}. */
    buttonProps: {
      type: 'button' as const,
      'aria-haspopup': 'menu' as const,
      'aria-expanded': open,
      'aria-controls': open ? menuId : undefined,
    },
  }
}
