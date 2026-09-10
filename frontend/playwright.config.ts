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

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'list' : 'html',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], ...launchOptions } },
    { name: 'mobile', use: { ...devices['Pixel 7'], ...launchOptions } },
  ],
  webServer: {
    command: 'npm run preview -- --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
