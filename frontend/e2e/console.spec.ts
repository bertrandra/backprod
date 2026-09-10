import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * U8's exit criteria, in a browser.
 *
 *   - **a tenant-app route is unreachable from the console and vice versa** —
 *     asserted by loading each and looking at which frame rendered, which is the
 *     part a unit test on the route tree cannot see;
 *   - **an erased user still appears in the directory, carrying `erased_at` and
 *     no identity**;
 *   - **erasure never presents itself as a delete** — the grounds are on screen
 *     before the operator commits, and the report keeps its two columns apart.
 *
 * The console reads `GET /staff/me`, not `GET /me`. These tests stub only the
 * former for the console pages, which is itself the assertion: a console that
 * still depended on the tenant session would find nothing and render an empty
 * navigation.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
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
    ],
  },
};

const TENANT_SESSION = {
  user_id: '22222222-2222-4222-8222-222222222222',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '33333333-3333-4333-8333-333333333333',
  tenant_id: '44444444-4444-4444-8444-444444444444',
  roles: ['TENANT_ADMIN'],
  permissions: ['projects.read', 'billing.read'],
  capabilities: [],
};

const ERASED_USER = {
  id: '55555555-5555-4555-8555-555555555555',
  email: null,
  display_name: null,
  created_at: '2025-01-01T10:00:00Z',
  erased_at: '2026-05-01T10:00:00Z',
  tenants: 2,
};

const PRESENT_USER = {
  id: '66666666-6666-4666-8666-666666666666',
  email: 'ada@acme.test',
  display_name: 'Ada',
  created_at: '2025-06-01T10:00:00Z',
  erased_at: null,
  tenants: 1,
};

const ERASURE = {
  user_id: PRESENT_USER.id,
  erased: { identity: 1, sessions: 3 },
  retained: {
    invoices: { count: 4, ground: 'accounting_record' },
    audit_entries: { count: 17, ground: 'audit_trail' },
  },
};

async function consoleStubs(page: Page) {
  const state = { erasures: 0 };

  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));

  // The tenant session, deliberately refused: a console that reached for it
  // would find a 403 rather than a shell painted the wrong colour.
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

  await page.route(/\/api\/v1\/staff\/tenants$/, (route) =>
    route.fulfill({
      json: {
        tenants: [{ id: 'tn-1', name: 'Acme Ltd', slug: 'acme' }],
        total: 1,
        limit: 25,
        offset: 0,
      },
    }),
  );

  await page.route(/\/api\/v1\/staff\/access-log/, (route) =>
    route.fulfill({ json: { entries: [], total: 0, limit: 50, offset: 0 } }),
  );

  await page.route(/\/api\/v1\/admin\/users/, (route) =>
    route.fulfill({
      json: { user: [PRESENT_USER, ERASED_USER], total: 2, limit: 25, offset: 0 },
    }),
  );

  await page.route(/\/api\/v1\/admin\/tenants/, (route) =>
    route.fulfill({ json: { tenant: [], total: 0, limit: 25, offset: 0 } }),
  );

  await page.route(/\/api\/v1\/admin\/erasures$/, (route) => {
    state.erasures += 1;

    return new Promise((resolve) => {
      setTimeout(() => {
        resolve(route.fulfill({ json: { erasure: ERASURE } }));
      }, 800);
    });
  });

  return state;
}

test.describe('the two shells', () => {
  test('a console route renders the console frame and never the tenant one', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    await expect(page.getByTestId('console-badge')).toHaveText('PLATFORM CONSOLE');
    await expect(page.getByTestId('console-band')).toContainText('crosses a tenant boundary');

    // The tenant frame's own controls are absent, not merely hidden: there is no
    // product to switch and no tenant to be in.
    await expect(page.locator('[data-testid="product-switcher"]')).toHaveCount(0);
    await expect(page.locator('[data-region="status-strip"]')).toHaveCount(0);
  });

  test('a tenant route renders the tenant frame and never the console band', async ({ page }) => {
    await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: TENANT_SESSION }));
    await page.route(/\/api\/v1\/products$/, (route) =>
      route.fulfill({
        json: { products: [{ id: TENANT_SESSION.product_id, code: 'atlas', name: 'Atlas' }] },
      }),
    );
    await page.route(/\/api\/v1\/projects/, (route) =>
      route.fulfill({ json: { projects: [], total: 0, limit: 25, offset: 0 } }),
    );

    await page.goto('/projects?product=atlas');

    await expect(page.locator('[data-testid="console-badge"]')).toHaveCount(0);
    await expect(page.locator('[data-testid="console-band"]')).toHaveCount(0);
  });

  test('the console navigation offers no tenant destination', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    await expect(page.getByTestId('console-badge')).toBeVisible();

    const links = await page
      .locator('[data-region="primary-nav"] a')
      .evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href') ?? ''));

    expect(links.length).toBeGreaterThan(0);

    for (const href of links) {
      expect(href.startsWith('/console/')).toBe(true);
    }
  });

  test('the console reads the staff identity, not the tenant session', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    // The navigation is populated, and `/me` answered 403 throughout. A console
    // still gated on the tenant session would show an empty nav here.
    //
    // Counted rather than asserted visible: at phone width the primary nav is in
    // the DOM but presented as the bottom bar instead (ui-spec.md §4.2), and this
    // test is about the nav having entries at all.
    await expect(page.locator('[data-region="primary-nav"] a').first()).toBeAttached();
    await expect(page.getByTestId('staff-identity')).toContainText('PLATFORM_ADMIN');
  });
});

test.describe('an erased person', () => {
  test('still has a row, with no identity and a date', async ({ page }) => {
    await consoleStubs(page);
    await page.goto('/console/directory?tab=users');

    await expect(page.locator(`[data-directory-row="${ERASED_USER.id}"]`)).toBeVisible();
    await expect(page.getByTestId('erased-identity')).toContainText('asked to be forgotten');

    const row = page.locator(`[data-directory-row="${ERASED_USER.id}"]`);

    await expect(row).toHaveAttribute('data-erased', 'true');
    await expect(row).toContainText(ERASED_USER.id);
    // Not a person with blanks where a name would be.
    await expect(row).not.toContainText('no name');
  });
});

test.describe('erasure', () => {
  test('names what the law keeps before it is performed, and reports it after', async ({ page }) => {
    const state = await consoleStubs(page);

    await page.goto('/console/erasure');

    // Before: the grounds, in words.
    const grounds = page.getByTestId('retention-grounds');
    await expect(grounds).toContainText('accounting record');
    await expect(grounds).toContainText('audit trail');
    await expect(page.getByText(/It is not a delete/i)).toBeVisible();

    await page.getByLabel('User identifier').fill(PRESENT_USER.id);
    await page.getByRole('button', { name: /Erase this person/ }).click();

    const confirmation = page.getByTestId('erasure-confirmation');
    await expect(confirmation).toContainText('none of it can be undone');
    await expect(confirmation).not.toContainText('Are you sure');

    await page.getByRole('button', { name: 'Erase permanently' }).click();

    // In flight: no report, because the counts are the server's.
    await expect(page.getByRole('button', { name: 'Working…' })).toBeVisible();
    await expect(page.getByTestId('erasure-report')).toHaveCount(0);

    await expect(page.getByTestId('erasure-report')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('erased-heading')).toContainText('Anonymised');
    await expect(page.getByTestId('retained-heading')).toContainText('Kept, as the law requires');
    await expect(page.locator('[data-retained="invoices"]')).toContainText('statutory period');
    expect(state.erasures).toBe(1);
  });
});

test.describe('the console on a phone', () => {
  test('never scrolls horizontally', async ({ page }) => {
    await consoleStubs(page);
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto('/console/directory?tab=users');
    await expect(page.getByTestId('erased-identity')).toBeVisible();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
  });
});
