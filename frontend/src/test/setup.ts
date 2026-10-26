import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach } from 'vitest'

// findBy… waits up to 5 s (default 1 s) for an element to appear: the first test in a
// file also loads its modules, which can exceed a second on slow disks or a busy machine.
configure({ asyncUtilTimeout: 5_000 })

// Unmount rendered components between tests (no Vitest globals are enabled).
afterEach(() => {
  cleanup()
})
