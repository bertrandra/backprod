import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';

import { expect, stubSession, test } from './support/app';

/**
 * The map of the maze, and the door into it — in a browser.
 *
 * Two things only a browser can settle. **The door**: that a platform role makes
 * the console reachable from the tenant application at all, and that a tenant
 * user is offered nothing — the second is a leak, not a cosmetic difference, and
 * a unit test on a component cannot see it rendered inside the real shell.
 * **The chain**: that it renders in dependency order with exactly one next step,
 * at both viewports.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
    roles: ['PLATFORM_ADMIN'],
    permissions: ['staff.self.read', 'staff.products.manage', 'staff.catalog.manage'],
  },
};

const PRODUCT = { id: '33333333-3333-4333-8333-333333333333', code: 'atlas', name: 'Atlas' };

const TENANT_SESSION = {
  user_id: '22222222-2222-4222-8222-222222222222',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: PRODUCT.id,
  tenant_id: '44444444-4444-4444-8444-444444444444',
  roles: ['TENANT_ADMIN'],
  permissions: ['projects.read', 'billing.read'],
  capabilities: [],
};

const step = (key: string, done: boolean, blocking: boolean, detail: object = {}) => ({
  key,
  done,
  blocking,
  detail,
});

const CHAIN = {
  product: PRODUCT,
  steps: [
    step('product', true, true, { code: 'atlas', active: true }),
    step('billing_identity', false, true, { missing: ['legal_name', 'country_code'] }),
    step('tax', false, false),
    step('plans', false, true, { count: 0 }),
    step('features', false, false, { count: 0 }),
    step('offers', false, true, { count: 0 }),
    step('published', false, true, { count: 0 }),
    step('advertised', false, true, { count: 0 }),
    step('payments', true, true),
  ],
  sellable: false,
  next: 'billing_identity',
};

async function consoleStubs(page: Page) {
  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
  await page.route(/\/api\/v1\/staff\/products$/, (route) =>
    route.fulfill({ json: { products: [{ ...PRODUCT, active: true }] } }),
  );
  await page.route(/\/api\/v1\/staff\/readiness/, (route) => route.fulfill({ json: CHAIN }));
  await page.route(/\/api\/v1\/me$/, (route) =>
    route.fulfill({
      status: 403,
      json: {
        error: {
          code: 'PERMISSION_DENIED',
          message: 'Platform staff are not members of any tenant.',
          details: {},
          request_id: 'req-1',
        },
      },
    }),
  );
}

test.describe('the platform badge', () => {
  test('names the roles of somebody who holds them', async ({ page }) => {
    // The catch-all FIRST, then the routes that matter. Playwright lets the
    // most recently registered route win, so a catch-all added last answers
    // `/staff/me` with `{}` and the door never appears — which is how the
    // negative test below first passed for entirely the wrong reason.
    await page.route(/\/api\/v1\//, (route) => route.fulfill({ json: {} }));
    await stubSession(page);
    await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: TENANT_SESSION }));
    await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));

    await page.goto('/projects');

    // The navigation already offers the platform screens — one tree now — so
    // what the bar adds is whose name the access log will carry.
    await expect(page.getByTestId('platform-badge')).toContainText('PLATFORM_ADMIN');
  });

  test('names nobody for a tenant user, which is the part that matters', async ({ page }) => {
    await page.route(/\/api\/v1\//, (route) => route.fulfill({ json: {} }));
    await stubSession(page);
    await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: TENANT_SESSION }));
    await page.route(/\/api\/v1\/staff\/me$/, (route) =>
      route.fulfill({
        status: 403,
        json: {
          error: {
            code: 'PERMISSION_DENIED',
            message: 'Not platform staff.',
            details: {},
            request_id: 'req-2',
          },
        },
      }),
    );

    await page.goto('/projects');

    // Waited on a real anchor first, so this is not asserting against a page
    // that simply had not rendered yet.
    await page.getByTestId('active-product').waitFor();
    await expect(page.getByTestId('platform-badge')).toHaveCount(0);
  });
});

test.describe('the setup chain', () => {
  test('renders in dependency order with exactly one next step', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/readiness');

    await page.getByTestId('setup-chain').waitFor();

    const keys = await page.locator('[data-step]').evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('data-step')),
    );

    expect(keys).toEqual([
      'product',
      'billing_identity',
      'tax',
      'plans',
      'features',
      'offers',
      'published',
      'advertised',
      'payments',
    ]);

    await expect(page.getByTestId('do-this-next')).toHaveCount(1);
    await expect(page.getByTestId('missing-billing_identity')).toContainText('legal_name');
  });

  test('is what /console alone lands on', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console');

    // An address typed without a section is a useful page rather than a 404 —
    // and it is the page that says what to do first.
    await expect(page.getByTestId('setup-chain')).toBeVisible();
    await expect(page.getByTestId('not-sellable')).toContainText('5 steps left');
  });

  test('passes an accessibility scan', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/readiness');
    await page.getByTestId('setup-chain').waitFor();

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });

  test('never scrolls horizontally', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/readiness');
    await page.getByTestId('setup-chain').waitFor();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );

    expect(overflow).toBe(false);
  });
});
