import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';

import { expect, stubSession, test } from './support/app';

/**
 * U9's accessibility pass, run rather than asserted.
 *
 * **Every route, both shells, on every CI run.** A one-off audit is a document
 * that goes stale the next time somebody adds a screen; this is a gate. The
 * scan is `axe-core` at WCAG 2.1 A and AA, which catches the things a
 * hand-rolled control forgets — a label bound to nothing, a contrast ratio
 * under 4.5:1, a landmark nested wrongly, a control reachable by mouse only.
 *
 * **What it cannot catch, and what is checked by hand below.** axe cannot tell
 * whether the focus *order* makes sense, whether a skip link goes somewhere
 * useful, or whether an error is announced when it appears. Those are asserted
 * directly: the tab order through region A into region B, the live region on a
 * failure, and `prefers-reduced-motion` actually removing the pulse rather than
 * only being declared.
 *
 * The routes are listed rather than discovered, deliberately. A discovered list
 * would silently shrink if the router stopped exporting one — `gate:screens` is
 * what proves the list is complete, and this proves each entry is usable.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: [
    'account.read',
    'billing.read',
    'billing.manage',
    'catalog.read',
    'catalog.manage',
    'jobs.read',
    'members.read',
    'messages.read',
    'notifications.read',
    'payments.read',
    'projects.read',
    'sales.read',
    'skin.manage',
    'subscription.read',
    'tax.read',
    'tax.manage',
    'tenant.read',
  ],
  capabilities: ['white_label'],
};

const STAFF = {
  staff: {
    user_id: '44444444-4444-4444-8444-444444444444',
    roles: ['PLATFORM_ADMIN'],
    permissions: [
      'staff.self.read',
      'staff.tenants.read',
      'staff.access_log.read',
      'support.read',
      'support.respond',
      'admin.finance.read',
      'admin.directory.read',
      'admin.health.read',
      'admin.audit.read',
      'admin.privacy.erase',
      'staff.navigation.manage',
    ],
  },
};

const EVERY_MENU = { hidden: [], hide_empty: false };

/**
 * Answers every read with an empty page, and the handful of shape-specific ones
 * with the shape they need.
 *
 * Empty states are the right subject for this scan: they are the screens most
 * often built last and least often looked at, and they carry the headings,
 * labels and landmarks that the populated version inherits. Where a control
 * only exists with data, the route-specific stubs below supply just enough.
 */
async function stubbed(page: Page) {
  // **Registered first, deliberately.** Playwright matches the *most recently*
  // registered route, so a catch-all added last silently wins over every
  // specific stub above it — which is exactly what happened the first time this
  // suite ran: half the screens were scanning an error state and passing,
  // because an error surface is itself accessible.
  await page.route(/\/api\/v1\//, (route) => {
    const url = route.request().url();
    const key = /\/api\/v1\/(?:[a-z-]+\/)*([a-z-]+)(?:\?|$)/.exec(url)?.[1] ?? 'items';

    return route.fulfill({
      json: {
        [key.replace(/-/g, '_')]: [],
        entries: [],
        periods: [],
        conversations: [],
        notifications: [],
        projects: [],
        jobs: [],
        offers: [],
        plans: [],
        features: [],
        quotes: [],
        orders: [],
        invoices: [],
        payments: [],
        credit_notes: [],
        tenants: [],
        users: [],
        subscriptions: [],
        unread: 0,
        total: 0,
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
  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
  await page.route(/\/api\/v1\/(me|staff\/me)\/navigation$/, (route) => route.fulfill({ json: { hidden: [] } }));
  await page.route(/\/api\/v1\/staff\/navigation$/, (route) =>
    route.fulfill({ json: { navigation: { platform_admin: EVERY_MENU, tenant_admin: EVERY_MENU, user: EVERY_MENU } } }),
  );

  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: SESSION.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  await page.route(/\/api\/v1\/tenants\/current$/, (route) =>
    route.fulfill({
      json: { tenant: { id: SESSION.tenant_id, name: 'Acme Ltd', slug: 'acme' } },
    }),
  );

  await page.route(/\/api\/v1\/tenants\/current\/skin$/, (route) =>
    route.fulfill({ json: { skin: {} } }),
  );

  await page.route(/\/api\/v1\/tax\/profile$/, (route) =>
    route.fulfill({
      json: {
        profile: {
          tenant_id: SESSION.tenant_id,
          customer_kind: 'B2B',
          country_code: 'FR',
          taxable_person: true,
          location_evidence: {},
          vat_number: 'FR12345678901',
          vat_number_status: 'VERIFIED',
          vat_number_verified_at: '2026-08-01T09:00:00Z',
          vat_number_country: 'FR',
          reverse_charge_available: true,
        },
      },
    }),
  );

  await page.route(/\/api\/v1\/tax\/rates/, (route) =>
    route.fulfill({ json: { on: '2026-03-15T00:00:00Z', rates: [] } }),
  );

  await page.route(/\/api\/v1\/billing\/profile$/, (route) =>
    route.fulfill({ json: { profile: { legal_name: 'Acme Ltd' } } }),
  );

  await page.route(/\/api\/v1\/subscription$/, (route) =>
    route.fulfill({ json: { subscription: null } }),
  );

  // The catalogue names its product in the header; the catch-all's empty
  // page has no product to name.
  await page.route(/\/api\/v1\/products\/[^/]+\/catalog$/, (route) =>
    route.fulfill({
      json: { product: { id: SESSION.product_id, code: 'atlas', name: 'Atlas' }, features: [], configuration: {} },
    }),
  );

  await page.route(/\/api\/v1\/admin\/queue/, (route) =>
    route.fulfill({
      json: {
        never_ran: false,
        last_run: { seconds_since_finished: 20 },
        unfinished_runs: 0,
        oldest_unfinished_seconds: null,
        backlog: { due: 0, oldest_due_seconds: null },
        stale_after_seconds: 300,
        stale: false,
      },
    }),
  );

  await page.route(/\/api\/v1\/admin\/metrics/, (route) =>
    route.fulfill({
      json: {
        product_id: SESSION.product_id,
        months: 12,
        turnover: [],
        top_offers: { month: '2026-03', offers: [] },
        renewal: [],
      },
    }),
  );
}

const TENANT_ROUTES = [
  '/',
  '/profile',
  '/organisation',
  '/members',
  '/branding',
  '/notifications',
  '/notification-settings',
  '/conversations',
  '/projects',
  '/jobs',
  '/catalogue',
  '/offers',
  '/quotes',
  '/orders',
  '/subscription',
  '/invoices',
  '/payments',
  '/credit-notes',
  '/billing-profile',
  '/tax',
  '/tax/rates',
  '/tax/reports',
] as const;

const CONSOLE_ROUTES = [
  '/console/tenants',
  '/console/conversations',
  '/console/access-log',
  '/console/metrics',
  '/console/directory',
  '/console/queue',
  '/console/audit',
  '/console/erasure',
  '/console/menus',
] as const;

async function scan(page: Page) {
  return new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
}

/** What a violation says, so a failure names the element rather than a rule id. */
function describe(results: Awaited<ReturnType<typeof scan>>): string {
  return results.violations
    .map(
      (violation) =>
        `${violation.id} (${violation.impact ?? 'unknown'}): ${violation.help}\n` +
        violation.nodes.map((node) => `    ${node.target.join(' ')}`).join('\n'),
    )
    .join('\n');
}

for (const route of TENANT_ROUTES) {
  test(`the tenant route ${route} has no accessibility violations`, async ({ page }) => {
    await stubbed(page);
    await page.goto(`${route}?product=atlas`);
    await expect(page.locator('[data-region="context-bar"]')).toBeVisible();

    const results = await scan(page);

    expect(describe(results), describe(results)).toBe('');
  });
}

for (const route of CONSOLE_ROUTES) {
  test(`the console route ${route} has no accessibility violations`, async ({ page }) => {
    await stubbed(page);
    await page.goto(route);
    await expect(page.getByTestId('platform-band')).toBeVisible();

    const results = await scan(page);

    expect(describe(results), describe(results)).toBe('');
  });
}

test.describe('what axe cannot see', () => {
  test('the keyboard reaches the navigation without a mouse', async ({ page, viewport }) => {
    test.skip((viewport?.width ?? 1280) < 768, 'The primary nav is the bottom bar at this width.');

    await stubbed(page);
    await page.goto('/projects?product=atlas');

    // Waited on a *link*, not on the region. The region is visible from the
    // first paint because it holds the loading skeleton, and tabbing then finds
    // nothing — which reads exactly like a keyboard trap and is not one. This
    // test asserted that for one run before the probe said otherwise.
    await expect(page.locator('[data-region="primary-nav"] a[href]').first()).toBeVisible();

    // Tab from the top and record where focus lands, in order. The assertion is
    // that a nav link is reachable within a bounded number of presses — an
    // unbounded tab hunt through a page of content is a keyboard user's
    // complaint, not a passing test.
    const reached: string[] = [];

    for (let press = 0; press < 15; press += 1) {
      await page.keyboard.press('Tab');

      const where = await page.evaluate(() => {
        const active = document.activeElement;

        if (active === null) {
          return '';
        }

        const region = active.closest('[data-region]')?.getAttribute('data-region') ?? 'none';

        return `${region}:${active.tagName.toLowerCase()}`;
      });

      reached.push(where);

      if (where.startsWith('primary-nav')) {
        break;
      }
    }

    expect(reached.some((where) => where.startsWith('primary-nav'))).toBe(true);
    // And region A comes first: the context bar is above the navigation on
    // screen, so tabbing must not jump past it and come back.
    expect(reached[0]?.startsWith('context-bar')).toBe(true);
  });

  test('a failure is announced, not only coloured', async ({ page }) => {
    await stubbed(page);
    await page.route(/\/api\/v1\/tax\/profile$/, (route) =>
      route.fulfill({
        status: 500,
        json: {
          error: {
            code: 'INTERNAL_ERROR',
            message: 'Something failed.',
            details: {},
            request_id: 'req-1',
          },
        },
      }),
    );

    await page.goto('/tax?product=atlas');

    // `role="alert"` is what makes a screen reader say it happened. Colour alone
    // reaches nobody who cannot see it.
    const alert = page.getByRole('alert');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('request req-1');
  });

  test('a loading state is announced as busy rather than as empty', async ({ page }) => {
    await stubbed(page);
    await page.route(/\/api\/v1\/billing\/invoices/, async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1_500));

      return route.fulfill({ json: { invoices: [], total: 0, limit: 25, offset: 0 } });
    });

    await page.goto('/invoices?product=atlas');

    // A skeleton with no announcement is a silent page for anybody listening.
    //
    // Scoped to `main`: the status strip is also a `role="status"` region, and
    // an unscoped locator matches both and fails on strict mode rather than on
    // the thing being asserted.
    const busy = page.getByRole('main').locator('[role="status"][aria-busy="true"]');
    await expect(busy).toBeVisible();
    await expect(busy).toContainText('Loading');
  });

  test('reduced motion removes the pulse rather than merely declaring it', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await stubbed(page);
    await page.route(/\/api\/v1\/billing\/invoices/, async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1_500));

      return route.fulfill({ json: { invoices: [], total: 0, limit: 25, offset: 0 } });
    });

    await page.goto('/invoices?product=atlas');

    const skeleton = page.locator('[role="status"] div[aria-hidden="true"]').first();
    await expect(skeleton).toBeVisible();

    // `motion-safe:animate-pulse` compiles to a rule inside a
    // prefers-reduced-motion media query, so the computed name must be none
    // here. Asserted computed, not by class: a class is a promise about CSS
    // that only the browser can keep.
    const animation = await skeleton.evaluate(
      (node) => getComputedStyle(node).animationName,
    );

    expect(animation).toBe('none');
  });

  test('every control is big enough to hit on a phone', async ({ page, viewport }) => {
    test.skip((viewport?.width ?? 1280) >= 768, 'This is the phone rule (ui-spec §4.2).');

    await stubbed(page);
    await page.goto('/tax?product=atlas');
    await expect(page.getByRole('button', { name: 'Save' })).toBeVisible();

    const small = await page.locator('button:visible, a:visible').evaluateAll((nodes) =>
      nodes
        .map((node) => ({
          label: (node.textContent ?? '').trim().slice(0, 30),
          height: node.getBoundingClientRect().height,
          width: node.getBoundingClientRect().width,
        }))
        // 44px is the rule ui-spec.md §4.2 sets. Inline links inside a
        // paragraph are exempt — they are text, not targets.
        .filter((box) => box.height > 0 && (box.height < 44 || box.width < 24)),
    );

    expect(JSON.stringify(small, null, 1)).toBe('[]');
  });
});
