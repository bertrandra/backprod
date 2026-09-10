import { fileURLToPath } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
// From vitest/config rather than vite: it is the same config plus the `test`
// block, so the toolchain has one file instead of two that can disagree about
// plugins or aliases.
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // The API is the PHP backend. Proxied in development so the browser sees
    // one origin and §31's strict CORS is not worked around with a permissive
    // development-only header that someone later ships.
    proxy: {
      '/api': {
        target: process.env.VITE_API_ORIGIN ?? 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test-setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
  },
});
