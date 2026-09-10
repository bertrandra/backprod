import { fileURLToPath } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
// From vitest/config rather than vite: it is the same config plus the `test`
// block, so the toolchain has one file instead of two that can disagree about
// plugins or aliases.
import { defineConfig } from 'vitest/config';

/**
 * The identity provider the browser suite talks to.
 *
 * The two `VITE_SUPABASE_*` values are replaced statically at build time, so a
 * bundle built without them has no provider and its sign-in screen says exactly
 * that — correct for an unconfigured deployment, and useless for a suite whose
 * every test needs a session. `--mode e2e` supplies fakes.
 *
 * Defined here rather than in a `.env.e2e` file so that the values, the mode that
 * uses them and the reason are one thing to read — and so nothing resembling a
 * real project URL is ever committed as configuration that a production build
 * could pick up by accident.
 */
const E2E_PROVIDER = {
  'import.meta.env.VITE_SUPABASE_URL': JSON.stringify('https://project.supabase.test'),
  'import.meta.env.VITE_SUPABASE_ANON_KEY': JSON.stringify('e2e-anon-key'),
};

export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss()],
  define: mode === 'e2e' ? E2E_PROVIDER : {},
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
}));
