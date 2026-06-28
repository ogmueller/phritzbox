import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

// Separate from vite.config.ts so the production build config stays untouched.
export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    // A concrete origin so jsdom provides a working localStorage.
    environmentOptions: { jsdom: { url: 'http://localhost/' } },
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    include: ['src/**/*.test.{ts,tsx}'],
  },
})
