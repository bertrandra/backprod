import { test as base, type Page } from '@playwright/test';

/**
 * The signed-in page every other spec starts from.
 *
 * U11 put a gate above the router: nothing renders without a token. All 178
 * browser tests were suddenly looking at a sign-in form, and there were two ways
 * out — weaken the gate for tests, or give the tests a session. Only the second
 * leaves the gate meaning anything.
 *
 * **U12 made this simpler and more honest.** The fixture used to seed a refresh
 * token into `localStorage` and stub an external provider's token endpoint. There
 * is no external provider now and nothing in storage to seed: the session resumes
 * because `POST /api/v1/auth/refresh` answers, which is a route in this
 * application's own contract. So the stub is an API stub like every other one in
 * these specs, and each spec exercises the real restore path on the way in.
 */
const SESSION = {
  access_token: 'e2e-access-token',
  token_type: 'Bearer',
  // Long enough that no spec is interrupted by a renewal it did not ask for.
  expires_in: 3600,
};

/**
 * Answers the session refresh, wherever it is registered.
 *
 * **Exported as well as applied by the fixture, because of Playwright's ordering.**
 * The most recently registered route wins — the trap U9 recorded — so a spec that
 * registers a catch-all (`/\/api\/v1\//`) after the fixture ran would answer the
 * refresh with whatever it answers everything with, and every test in that file
 * would land on the sign-in form. Those specs call this again after their own
 * stubs, which is a line each and visible where it matters.
 */
export async function stubSession(page: Page): Promise<void> {
  await page.route('**/api/v1/auth/refresh', (route) =>
    route.fulfill({ status: 200, json: SESSION }),
  );
}

export const test = base.extend({
  page: async ({ page }, use) => {
    await stubSession(page);

    await use(page);
  },
});

export { expect } from '@playwright/test';

/**
 * A latch a route handler can wait on, so a test decides when a read lands.
 *
 * Every transient state — a skeleton, a busy region, a frame painted while its
 * data is still outstanding — lasts exactly as long as the request behind it.
 * Stubbing that request with a `setTimeout` makes the state last a fixed number
 * of milliseconds of **wall clock**, and then the assertion is racing the
 * machine: the application's own start-up stretches under load while the sleep
 * does not, so the window closes before the page is ready to be looked at. That
 * is a test that fails on a busy laptop and passes in CI, which is the shape of
 * a test people learn to ignore.
 *
 * Held open instead, the window is unbounded and the assertion is about the
 * application rather than about how fast this machine happens to be today.
 */
export function heldOpen(): { held: Promise<void>; release: () => void } {
  let release!: () => void;
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });

  return { held, release };
}
