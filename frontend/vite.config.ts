import { fileURLToPath } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
// From vitest/config rather than vite: it is the same config plus the `test`
// block, so the toolchain has one file instead of two that can disagree about
// plugins or aliases.
import { defineConfig } from 'vitest/config';

/**
 * No `define`, and no modes.
 *
 * U11 needed a `--mode e2e` that baked a stubbed Supabase URL into the bundle,
 * because the browser suite could not reach past the sign-in gate without an
 * identity provider to talk to. U12 issues tokens from PHP, so signing in is an
 * ordinary API call the specs stub like any other, and a build needs no keys of
 * any kind. One bundle, built one way, deployed anywhere.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // The pane that previews this app assigns a port through PORT when 5173
    // is taken; a strict, hard-coded one fought a stale process three times.
    port: process.env.PORT === undefined ? 5173 : Number(process.env.PORT),
    strictPort: process.env.PORT !== undefined,
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
