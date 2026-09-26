import { expect, test } from './support/app';

import type { Page } from '@playwright/test';

/**
 * How a platform administrator moves between the console's screens.
 *
 * There was a menu of the console's own — a bar of dropdowns over every
 * `/console/*` screen and a full-screen sheet on a phone, with a "Tenant app"
 * link back (ADR-047). The operator asked for both to go (2026-09-18): the
 * one shell's rail already lists every platform screen the role allows, so
 * the bar said the same thing twice, and the way back was a link to a
 * front door that the rail's own tenant entries already open. What this
 * suite proves is what remains: every admin screen is one interaction away
 * from any other — the rail on a desktop, the one drawer on a phone — and
 * nothing else is on top of the screen.
 */
const STAFF = {
  staff: {
    user_id: '11111111-1111-4111-8111-111111111111',
    roles: ['PLATFORM_ADMIN'],
    permissions: [
      'staff.self.read',
      'staff.products.manage',
      'staff.features.manage',
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
      'staff.mail.manage',
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
  await page.route(/\/api\/v1\/staff\/me\/navigation$/, (route) => route.fulfill({ json: { hidden: [] } }));
  await page.route(/\/api\/v1\/staff\/products$/, (route) => route.fulfill({ json: { products: [PRODUCT] } }));
  // No membership: a platform administrator and nothing else.
  await page.route(/\/api\/v1\/me$/, (route) =>
    route.fulfill({
      status: 403,
      json: { error: { code: 'PERMISSION_DENIED', message: 'Not a member.', details: {}, request_id: 'r' } },
    }),
  );
}

test.describe('moving between console screens', () => {
  test('is the rail on a desktop and the drawer on a phone, with nothing on top of the screen', async ({
    page,
    viewport,
  }) => {
    await consoleStubs(page);
    await page.goto('/console/tenants');

    const narrow = (viewport?.width ?? 1280) < 768;

    // No bar over the view, no sheet of its own, no way-back link: the
    // operator asked for all three to go.
    await expect(page.getByTestId('console-menu')).toHaveCount(0);
    await expect(page.getByTestId('console-menu-sheet')).toHaveCount(0);
    await expect(page.getByTestId('tenant-app-link')).toHaveCount(0);

    if (narrow) {
      await page.getByRole('button', { name: 'Menu' }).click();
      await expect(page.getByTestId('menu-sheet')).toBeVisible();
      // Seventeen admin screens for a full administrator, in the one drawer.
      await expect(page.getByTestId('menu-sheet').locator('[data-nav-more]')).toHaveCount(17);
      // And no sign-out there: the account circle carries it at every width.
      await expect(page.getByTestId('menu-sheet').getByRole('button', { name: 'Sign out' })).toHaveCount(0);
      await page.getByTestId('menu-sheet').locator('[data-nav-more="audit"]').click();
    } else {
      await expect(page.locator('[data-region="primary-nav"] [data-nav]')).toHaveCount(17);
      // The section headings read as headings: named, above their entries.
      await expect(page.locator('[data-nav-section="setup"]')).toBeVisible();
      await page.locator('[data-nav="audit"]').click();
    }

    await expect(page).toHaveURL(/\/console\/audit$/);
  });
});
