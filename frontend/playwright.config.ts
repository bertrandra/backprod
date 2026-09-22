import { defineConfig, devices } from '@playwright/test';

/**
 * U0 has no screens, so this suite has one smoke test: the application builds,
 * serves and mounts. Its value is that U1's first real screen inherits a
 * working browser harness instead of debugging one.
 *
 * Both viewports from the start. `docs/ui-roadmap.md` says mobile is not a
 * milestone, and a project whose E2E suite only ever ran at desktop width would
 * discover that promise was decorative.
 */
/**
 * A sandbox may ship a Chromium that does not match this Playwright's expected
 * revision. `PLAYWRIGHT_CHROMIUM_PATH` points at the one that is there;
 * unset — which is the case in CI — Playwright resolves its own as usual.
 */
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const launchOptions = executablePath === undefined ? {} : { launchOptions: { executablePath } };

/**
 * Not Vite's preview default (4173): that is the port every Vite project on
 * the machine reaches for, so a product developed beside this one is likely
 * to be sitting on it. `PLAYWRIGHT_PORT` overrides it when even this one is
 * taken.
 */
const PORT = process.env.PLAYWRIGHT_PORT ?? '4713';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'list' : 'html',
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], ...launchOptions } },
    { name: 'mobile', use: { ...devices['Pixel 7'], ...launchOptions } },
  ],
  webServer: {
    // `--host 127.0.0.1` explicitly. Vite's preview default is `localhost`,
    // which on a dual-stack machine resolves to ::1 first — so the server binds
    // to IPv6 while this waits on IPv4 and the whole thing times out after two
    // minutes saying nothing useful. That is exactly how this first failed in
    // CI, having worked locally.
    command: `npm run preview -- --host 127.0.0.1 --port ${PORT} --strictPort`,
    url: `http://127.0.0.1:${PORT}`,
    // Never reuse. With `reuseExistingServer` on, whatever is already listening
    // on the port is taken for this build — and on 2026-09-21 that was another
    // application's dev server, which made 262 specs fail against a page that
    // was not this one. `--strictPort` then makes a taken port a one-line
    // refusal instead of a fifteen-minute mystery.
    reuseExistingServer: false,
    timeout: 60_000,
    // Piped, not swallowed. A webServer that fails to start is the least
    // self-explanatory failure in this toolchain, and silence turns a one-line
    // cause into a guessing game.
    stdout: 'pipe',
    stderr: 'pipe',
  },
});
