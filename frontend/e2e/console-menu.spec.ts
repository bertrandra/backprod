import { expect, test } from './support/app';

import type { Page } from '@playwright/test';

/**
 * The console's own navigation (ADR-047), at both widths.
 *
 * A platform administrator used to have no way between the admin screens
 * that was theirs: the left rail listed them among everything else on a
 * desktop, and a phone's five-slot bottom bar buried most of them under
 * "More". Now every console screen carries a menu of exactly the platform's
 * screens — grouped, the current one marked, one way back — as a bar on a
 * desktop and a full-screen sheet on a phone. What this suite proves is
 * reachability: every admin screen the role allows is one interaction away
 * from any other.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
    roles: ['PLATFORM_ADMIN'],
    permissions: [
      'staff.self.read',
      'staff.products.manage',
      'staff.catalog.manage',
      'staff.tenants.read',
      'staff.tenants.manage',
      'support.read',
      'admin.directory.read',
      'admin.finance.read',
      'staff.grant',
      'admin.health.read',
      'admin.audit.read',
      'staff.access_log.read',
      'admin.privacy.erase',
    ],
  },
};

const PRODUCT = { id: '33333333-3333-4333-8333-333333333333', code: 'atlas', name: 'Atlas', active: true };

async function consoleStubs(page: Page) {
  // Every other console read answers empty: the screens are not what is under
  // test, only the way between them. Registered first, because Playwright
  // tries routes newest-first and this must be the one that loses.
  await page.route(/\/api\/v1\/(staff|admin)\//, (route) =>
    route.fulfill({ json: { tenants: [], entries: [], users: [], jobs: [], erasures: [], total: 0, limit: 25, offset: 0 } }),
  );
  await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
  await page.route(/\/api\/v1\/staff\/products$/, (route) => route.fulfill({ json: { products: [PRODUCT] } }));
  // No membership: a platform administrator and nothing else.
  await page.route(/\/api\/v1\/me$/, (route) =>
    route.fulfill({
      status: 403,
      json: { error: { code: 'PERMISSION_DENIED', message: 'Not a member.', details: {}, request_id: 'r' } },
    }),
  );
}

test.describe('the console menu', () => {
  test('lists every admin screen the role allows, grouped, and marks the current one', async ({
    page,
    viewport,
  }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    const narrow = (viewport?.width ?? 1280) < 768;

    if (narrow) {
      // A phone: the bar is gone and the button in the context bar opens the sheet.
      await expect(page.getByTestId('console-menu')).toBeHidden();
      await page.getByTestId('console-menu-button').click();
      await expect(page.getByTestId('console-menu-sheet')).toBeVisible();
    } else {
      await expect(page.getByTestId('console-menu')).toBeVisible();
      await expect(page.getByTestId('console-menu-button')).toBeHidden();
    }

    const menu = narrow ? page.getByTestId('console-menu-sheet') : page.getByTestId('console-menu');

    // The three groups the tree leads with.
    await expect(menu.locator('[data-console-section]')).toHaveText([/Setup/, /Customers/, /Platform/]);
    // Fourteen admin screens for a full administrator, and the current one is the page.
    await expect(menu.locator('[data-console-nav]')).toHaveCount(14);
    await expect(menu.locator('[data-console-nav][aria-current="page"]')).toHaveAttribute(
      'data-console-nav',
      'tenants',
    );
  });

  test('takes somebody from one admin screen to another, and back to the front door', async ({
    page,
    viewport,
  }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    const narrow = (viewport?.width ?? 1280) < 768;

    if (narrow) {
      await page.getByTestId('console-menu-button').click();
      await page.getByTestId('console-menu-sheet').locator('[data-console-nav="audit"]').click();
      await expect(page.getByTestId('console-menu-sheet')).toBeHidden();
    } else {
      // Open the group, then the screen: a native disclosure, so one click each.
      await page.getByTestId('console-menu').locator('[data-console-section="platform"] summary').click();
      await page.getByTestId('console-menu').locator('[data-console-nav="audit"]').click();
    }

    await expect(page).toHaveURL(/\/console\/audit$/);

    // The way back: no membership, so the front door. Looked for inside the
    // menu that is showing — the bar and the sheet each carry one.
    if (narrow) {
      await page.getByTestId('console-menu-button').click();
    }
    const menu = narrow ? page.getByTestId('console-menu-sheet') : page.getByTestId('console-menu');
    await expect(menu.getByTestId('tenant-app-link')).toHaveAttribute('href', '/');
  });

  test('is absent from the application', async ({ page }) => {
    await page.route(/\/api\/v1\/me$/, (route) =>
      route.fulfill({
        json: {
          user_id: 'u', email: 'ada@acme.test', display_name: 'Ada', product_id: PRODUCT.id, tenant_id: 't',
          roles: ['TENANT_ADMIN'], permissions: ['projects.read'], capabilities: [],
        },
      }),
    );
    await page.route(/\/api\/v1\/staff\/me$/, (route) => route.fulfill({ json: STAFF }));
    await page.route(/\/api\/v1\/products$/, (route) => route.fulfill({ json: { products: [PRODUCT] } }));
    await page.route(/\/api\/v1\/projects/, (route) => route.fulfill({ json: { projects: [], total: 0, limit: 25, offset: 0 } }));

    await page.goto('/projects?product=atlas');

    // A tenant screen, even for somebody who is also on staff: region C's header
    // is the console's, not the application's.
    await expect(page.locator('[data-region="view-body"]')).toBeVisible();
    await expect(page.getByTestId('console-menu')).toHaveCount(0);
    await expect(page.getByTestId('console-menu-button')).toHaveCount(0);
  });
});
