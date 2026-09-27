import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
// From vitest/config rather than vite: it is the same config plus the `test`
// block, so the toolchain has one file instead of two that can disagree about
// plugins or aliases.
import { defineConfig } from 'vitest/config';

/**
 * Which version and build this bundle is (2026-09-27), shown at the end of the
 * account menus so that "which one is deployed?" has an answer on screen.
 *
 * - **version**: the contract's `info.version`, the one version number this
 *   repository keeps — read, not restated, so the two cannot disagree;
 * - **commit**: the short hash it was built from, marked `+changes` when the
 *   working tree was not clean, so a local build never passes as a commit;
 * - **built at**: the moment of the build.
 *
 * Anything git cannot answer reads `unknown` rather than failing the build.
 */
function buildInfo(): { version: string; commit: string; builtAt: string } {
  const git = (command: string): string => {
    try {
      return execSync(command, { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
    } catch {
      return '';
    }
  };

  let version = 'unknown';

  try {
    const contract = JSON.parse(readFileSync(new URL('../openapi.json', import.meta.url), 'utf8')) as {
      info?: { version?: unknown };
    };
    version = typeof contract.info?.version === 'string' ? contract.info.version : version;
  } catch {
    // No contract beside the frontend: say so rather than invent a number.
  }

  const hash = git('git rev-parse --short HEAD');
  const dirty = hash !== '' && git('git status --porcelain') !== '';

  return {
    version,
    commit: hash === '' ? 'unknown' : `${hash}${dirty ? '+changes' : ''}`,
    builtAt: new Date().toISOString(),
  };
}

const build = buildInfo();

/**
 * No modes, and one `define` — the build stamp above, which is a fact about
 * the bundle and not a key or a setting.
 *
 * U11 needed a `--mode e2e` that baked a stubbed Supabase URL into the bundle,
 * because the browser suite could not reach past the sign-in gate without an
 * identity provider to talk to. U12 issues tokens from PHP, so signing in is an
 * ordinary API call the specs stub like any other, and a build needs no keys of
 * any kind. One bundle, built one way, deployed anywhere.
 */
export default defineConfig({
  define: {
    __APP_VERSION__: JSON.stringify(build.version),
    __BUILD_COMMIT__: JSON.stringify(build.commit),
    __BUILD_TIME__: JSON.stringify(build.builtAt),
  },
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
