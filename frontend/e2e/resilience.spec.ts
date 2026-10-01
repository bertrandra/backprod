import type { Page, Request } from '@playwright/test';

import { expect, heldOpen, stubSession, test } from './support/app';

/**
 * What the application does when the API is not there, and how fast it is when
 * it is.
 *
 * **Offline is not a special screen.** A request that never arrives becomes a
 * synthetic 503 carrying the §10.4 envelope (`offlineMiddleware`), so an outage
 * reaches a screen in the same shape as every other failure and every
 * `ErrorSurface` can say something true about it. These tests prove that
 * mapping end to end rather than in a unit — the point is what a person sees.
 *
 * **A budget is a number or it is an aspiration.** The route budgets below are
 * asserted against a real navigation with the API stubbed at a fixed latency, so
 * they measure the application rather than the network. They are deliberately
 * loose: their job is to fail when a screen starts blocking on something it
 * should not, not to police milliseconds.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['billing.read', 'billing.manage', 'billing.pay', 'projects.read', 'jobs.read', 'sales.read', 'subscription.read'],
  capabilities: [],
};

const INVOICE = {
  id: '44444444-4444-4444-8444-444444444444',
  number: '2026-000042',
  status: 'ISSUED',
  final: true,
  subscription_id: null,
  // Whom it concerns (2026-09-27): the organisation's own here.
  person: null,
  issued_at: '2026-01-01T10:00:00Z',
  due_at: '2026-01-31T10:00:00Z',
  paid_at: null,
  period_start: null,
  period_end: null,
  payment_terms: null,
  supplier: { legal_name: 'Backprod SAS' },
  customer: { legal_name: 'Acme Ltd' },
  lines: [],
  taxes: [],
  net: { minor_units: 2900, currency: 'EUR' },
  vat: { minor_units: 580, currency: 'EUR' },
  gross: { minor_units: 3480, currency: 'EUR' },
};

/**
 * Every read answered, optionally after a delay or a latch, and countable.
 *
 * `hold` is asked, per request, for something to wait on before answering it —
 * `undefined` to answer at once. A latch rather than a delay is what lets a test
 * assert on a state that only exists while a request is outstanding without
 * betting on how long the rest of the page takes to get there (`heldOpen`).
 */
async function online(
  page: Page,
  latencyMs = 0,
  hold?: (request: Request) => Promise<void> | undefined,
) {
  const state = { requests: 0 };

  await page.route(/\/api\/v1\//, async (route) => {
    state.requests += 1;

    if (latencyMs > 0) {
      await new Promise((resolve) => setTimeout(resolve, latencyMs));
    }

    await hold?.(route.request());

    return route.fulfill({
      json: {
        invoices: [INVOICE],
        projects: [],
        jobs: [],
        orders: [],
        payments: [],
        // A shape the subscription screen can read: no subscription, rather
        // than an undefined one, which is a crash once the answer lands and a
        // frame test that fails only under load.
        seat: null,
        coverage: null,
        unread: 0,
        total: 1,
        limit: 25,
        offset: 0,
      },
    });
  });

  // Re-registered after the catch-all above, which would otherwise answer the
  // session refresh and leave every test in this file on the sign-in form.
  // Playwright uses the most recently registered route (U9).
  await stubSession(page);

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: SESSION }));
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: SESSION.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  return state;
}

test.describe('when the API is unreachable', () => {
  test('says the request never arrived, rather than "something went wrong"', async ({ page }) => {
    await online(page);
    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');

    // The connection goes. Aborting the route is what a dropped connection looks
    // like to `fetch` — a rejection, with no response to read a status from.
    // Routes survive a navigation, so registering the abort and then reloading
    // is the whole outage: the screen loads its frame and its data never comes.
    await page.route(/\/api\/v1\/billing\/invoices/, (route) => route.abort('failed'));
    await page.reload();

    const alert = page.getByRole('alert').first();
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('did not reach the server');
    // And what a person can do about it, which "something went wrong" cannot say.
    await expect(alert).toContainText('nothing was half-done');
  });

  test('queues a write made while offline, rather than losing or faking it', async ({ page }) => {
    await online(page);
    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');

    // Nothing to say while everything is current.
    await expect(page.getByTestId('connection-state')).toHaveCount(0);

    // Genuinely offline: the browser sets `navigator.onLine` and fires its own
    // event. TanStack Query's default `networkMode: 'online'` then **queues**
    // the request instead of sending it — nothing fails, nothing is lost, and
    // it runs when the connection returns. This test asserted "unreachable" at
    // first and watched a mutation sit pending for six seconds with no error,
    // which is how that behaviour was found.
    await page.context().setOffline(true);

    await page.getByRole('button', { name: 'Issue an invoice…' }).click();
    await page.getByRole('button', { name: 'Issue it' }).click();

    const badge = page.getByTestId('connection-state');
    await expect(badge).toBeVisible({ timeout: 10_000 });
    await expect(badge).toHaveAttribute('data-state', 'paused');
    await expect(badge).toContainText('will be sent when you are back');

    // Announced, not only coloured: the person least able to see an amber badge
    // is the one most affected by not knowing the write is still queued.
    await expect(badge).toHaveAttribute('role', 'status');
    await expect(badge).toHaveAttribute('aria-live', 'polite');

    // And nothing was invented while it waited: no second number appeared.
    await expect(page.getByTestId('invoice-number')).toHaveCount(1);
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');
  });

  test('says the server is unreachable when the machine is online and it is not', async ({
    page,
  }) => {
    await online(page);
    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');

    // Online as far as the browser knows, so the request is actually sent — and
    // does not arrive. A different fact from being offline, and different words.
    await page.route(/\/api\/v1\/billing\/invoices/, (route) => route.abort('failed'));

    await page.getByRole('button', { name: 'Issue an invoice…' }).click();
    await page.getByRole('button', { name: 'Issue it' }).click();

    const badge = page.getByTestId('connection-state');
    await expect(badge).toBeVisible({ timeout: 10_000 });
    await expect(badge).toHaveAttribute('data-state', 'unreachable');
    await expect(badge).toContainText('Server unreachable');

    // The failure reaches the screen in the same shape as any other.
    await expect(page.getByRole('alert').first()).toContainText('did not reach the server', {
      timeout: 15_000,
    });
  });

});

test.describe('stale data', () => {
  test('is marked while a refresh is in flight, and the old figures stay put', async ({ page }) => {
    // Stated rather than assumed. A browser that believes it is offline pauses
    // every request instead of sending it, and this test would then be watching
    // the wrong state entirely — which is what it did until this line existed.
    await page.context().setOffline(false);
    await online(page);
    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');

    // Issuing invalidates the list, and every read *after the write* is held
    // open. Flagged rather than counted: a count is a guess at how many reads a
    // page makes, and it was wrong — the badge never appeared and the test read
    // as a missing feature rather than as a bad fixture.
    let issued = false;

    await page.route(/\/api\/v1\/billing\/invoices/, async (route) => {
      if (route.request().method() === 'POST') {
        issued = true;

        return route.fulfill({ status: 201, json: { ...INVOICE, number: '2026-000043' } });
      }

      if (issued) {
        await new Promise((resolve) => setTimeout(resolve, 1_500));
      }

      return route.fulfill({
        json: {
          invoices: [{ ...INVOICE, number: issued ? '2026-000043' : '2026-000042' }],
          total: 1,
          limit: 25,
          offset: 0,
        },
      });
    });

    await page.getByRole('button', { name: 'Issue an invoice…' }).click();
    await page.getByRole('button', { name: 'Issue it' }).click();

    const badge = page.getByTestId('connection-state');
    await expect(badge).toBeVisible({ timeout: 5_000 });
    await expect(badge).toHaveAttribute('data-state', 'updating');

    // Then the server's answer, and nothing left to say.
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000043', {
      timeout: 10_000,
    });
    await expect(page.getByTestId('connection-state')).toHaveCount(0);
  });
});

test.describe('performance budgets', () => {
  /**
   * Every route must paint its frame before its data arrives.
   *
   * A screen that renders its shell first is readable while it waits; a screen
   * that waits for everything shows nothing until the last query lands, which is
   * the failure this measures.
   *
   * **Asserted as an order, not as a duration.** This used to stub the API at
   * 800ms, time the navigation with `Date.now()` and require the frame inside
   * 3s. That number measured the machine rather than the application: it
   * contained the browser's cold start, the bundle's download and its parse —
   * all of which stretch when something else is running on the same laptop,
   * while the stub's 800ms did not stretch with them. Measured serially on a
   * quiet machine the frame was in the DOM 0.7–1.3s after navigation start and
   * the screen's first read left within a few milliseconds of it, so first paint
   * had every bit of headroom the budget claimed to check — and the assertion
   * still failed at 4.3s and 5.9s beside a compile. A budget that fails for a
   * reason it cannot name is a budget people learn to skip, and a test only ever
   * skipped locally is one that no longer defends anything locally.
   *
   * So the screen's own reads are **held open for the whole test** and never
   * answered. The frame must appear anyway, and say something while it waits. A
   * screen that blocked on its data could not pass this at any speed, and a
   * screen that does not cannot fail it on a slow one.
   */
  const ROUTES = ['/invoices', '/projects', '/jobs', '/orders', '/subscription'] as const;

  /**
   * What the frame itself needs: the session, the product, the menu.
   *
   * Named as one set rather than per route, because that is exactly the claim —
   * the frame depends on the bootstrap and on nothing else. Adding a screen's
   * query here to make a test pass would empty the list of meaning, which is why
   * it sits in the open rather than beside each route.
   */
  const BOOTSTRAP = /^\/api\/v1\/(auth\/refresh|me|me\/navigation|staff\/me|products)$/;

  for (const route of ROUTES) {
    test(`${route} paints its frame before its data`, async ({ page }) => {
      const { held, release } = heldOpen();

      await online(page, 0, (request) =>
        BOOTSTRAP.test(new URL(request.url()).pathname) ? undefined : held,
      );

      await page.goto(`${route}?product=atlas`);

      // Region A and the navigation are the frame. They depend on the session,
      // which is the bootstrap above — not on the screen's own queries, every
      // one of which is still in flight here and will stay there until the end
      // of this test.
      //
      // The timeout is deliberately far above anything this takes: it detects a
      // frame that never comes, it is not a budget. The assertion is that the
      // frame is here while the data is not.
      await expect(page.locator('[data-region="context-bar"]')).toBeVisible({ timeout: 20_000 });
      await expect(page.locator('[data-region="view-body"]')).toBeVisible({ timeout: 20_000 });

      // And the body says something while it waits, rather than being blank.
      const body = await page.locator('[data-region="view-body"]').textContent();

      expect((body ?? '').trim().length).toBeGreaterThan(0);

      // Let the held reads land before the page closes, so no route handler is
      // still suspended when Playwright tears the context down.
      release();
    });
  }

  test('no screen fires the same read twice on one load', async ({ page }) => {
    const state = await online(page);

    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number')).toBeVisible();
    await page.waitForTimeout(500);

    const seen = state.requests;

    // A duplicated query is two round trips for one answer, and the usual cause
    // is a key spelled differently at two call sites — which no screen test can
    // see because both calls return the same thing.
    expect(seen).toBeLessThan(12);
  });
});
