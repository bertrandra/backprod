import { test as base } from '@playwright/test';

/**
 * The signed-in page every other spec starts from.
 *
 * U11 put a gate above the router: nothing renders without a token. That is the
 * point of it, and it means all 178 existing browser tests were suddenly looking
 * at a sign-in form. There were two ways out — weaken the gate for tests, or give
 * the tests a session — and only the second one leaves the gate meaning anything.
 *
 * **A session, obtained the way a returning visitor obtains one.** A refresh
 * token is seeded into storage before the page's first script runs, and the
 * provider's token endpoint answers it. So every spec exercises the restore path
 * on the way in rather than bypassing it, and a defect in that path fails
 * everywhere instead of nowhere.
 *
 * The URL is the one `--mode e2e` bakes into the bundle. A real Supabase project
 * is never contacted: nothing here leaves the browser.
 */
export const AUTH_ORIGIN = 'https://project.supabase.test';

/** Long enough that no spec is interrupted by a renewal it did not ask for. */
const GRANT = {
  access_token: 'e2e-access-token',
  refresh_token: 'e2e-refresh-token',
  expires_in: 3600,
};

export const test = base.extend({
  page: async ({ page }, use) => {
    // Before any application script, so the store's first read finds it. Setting
    // it after `goto` would be a race the test loses about half the time.
    await page.addInitScript(() => {
      try {
        window.localStorage.setItem('backprod.refresh', 'e2e-refresh-token');
      } catch {
        // A browser context with storage blocked is not what any of these specs
        // are about, and the sign-in screen covers it.
      }
    });

    // Registered here, which is *before* every stub a spec adds — and that is the
    // right way round. Playwright matches the most recently registered route, so
    // a spec's own catch-all (`/\/api\/v1\//`) wins over this only if it also
    // matches this URL, which it deliberately does not: the identity provider is
    // a different origin from the API.
    await page.route(`${AUTH_ORIGIN}/auth/v1/token**`, (route) =>
      route.fulfill({ status: 200, json: GRANT }),
    );

    await use(page);
  },
});

export { expect } from '@playwright/test';
