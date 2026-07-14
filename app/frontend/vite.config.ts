import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'serve' ? '/' : '/frontend/',
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        // Docker publishes the app on PHRITZBOX_PORT (see docker/.env), but
        // Caddy/FrankenPHP only serves the `localhost` vhost — any other Host
        // (e.g. 127.0.0.1) gets an empty 200. With changeOrigin the outgoing
        // Host must therefore stay `localhost`.
        target: 'http://localhost:38080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: '../public/frontend',
    emptyOutDir: true,
  },
}))
