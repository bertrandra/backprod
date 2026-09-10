import { defineConfig, devices } from '@playwright/test';

/**
 * The same suite, against the built bundle instead of Vite's preview server.
 *
 * `playwright.config.ts` serves the application the way a developer does. This
 * one points at whatever is already serving the *bundle* — the shim, the built
 * assets, the rewrite rules and the Content-Security-Policy that ships with it —
 * because those four are exactly what the normal suite never sees, and each of
 * them can break the application on its own without a single test noticing.
 *
 * The policy is the reason this exists. A CSP is a header nobody writes a test
 * for and everybody feels: one missing `connect-src` and every request the browser
 * makes to the identity provider is refused, silently, in production only.
 *
 * `bin/verify-dist.sh --browser` starts the server and sets `PLAYWRIGHT_DIST_URL`.
 * There is no `webServer` here on purpose: the thing under test is a bundle
 * somebody else is serving.
 */
const url = process.env.PLAYWRIGHT_DIST_URL;

if (url === undefined) {
  throw new Error('PLAYWRIGHT_DIST_URL is not set. Run this through bin/verify-dist.sh --browser.');
}

const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const launchOptions = executablePath === undefined ? {} : { launchOptions: { executablePath } };

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  reporter: 'list',
  use: { baseURL: url, trace: 'off' },
  // Desktop only. Both viewports are the normal suite's job; this one is asking
  // whether the *bundle* serves and runs, and that answer does not change with
  // the width of the window.
  projects: [{ name: 'dist', use: { ...devices['Desktop Chrome'], ...launchOptions } }],
});
