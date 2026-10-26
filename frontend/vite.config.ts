import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// File-change events do not reach containers from every host (e.g. Docker on a
// Windows drive), so the dev server can poll instead when DEV_SERVER_POLLING=true.
const polling = process.env.DEV_SERVER_POLLING === 'true'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    watch: polling ? { usePolling: true, interval: 500 } : undefined,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
  },
})
