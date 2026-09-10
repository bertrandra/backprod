import { expect, test, type Page } from '@playwright/test';

/**
 * U3's first exit criterion, in a browser: **the unread badge is correct after
 * reading somewhere else.**
 *
 * The unit tests assert the invalidation. This asserts the thing a person would
 * notice — the number in region A, on every screen, after an action taken in
 * another tab. It is the case a locally-decremented counter gets wrong, and no
 * amount of correct arithmetic fixes.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['notifications.read', 'notifications.manage', 'billing.read'],
  capabilities: [],
};

function notification(id: string, read: boolean) {
  return {
    id: `0000000${id}-0000-4000-8000-000000000000`,
    type: 'payment.failed',
    category: 'BILLING',
    payload: {},
    legal_effect: false,
    created_at: '2026-01-01T10:00:00Z',
    read_at: read ? '2026-01-02T10:00:00Z' : null,
  };
}

/**
 * A backend that answers from a counter this test controls.
 *
 * `readElsewhere` is the other tab: it moves the server's state without the page
 * under test doing anything, which is exactly the situation a local counter
 * cannot survive.
 */
async function inbox(page: Page, session: Record<string, unknown> = SESSION) {
  const state = { unread: 3, readHere: 0, readElsewhere: 0 };

  // Regular expressions rather than globs: `?` is a wildcard in Playwright's glob
  // syntax, so `notifications?*` matched the wrong things and the page fell back
  // to an unstubbed request.
  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: session }));

  await page.route(/\/api\/v1\/notifications\/unread-count/, (route) =>
    route.fulfill({
      json: { unread: Math.max(0, state.unread - state.readHere - state.readElsewhere) },
    }),
  );

  await page.route(/\/api\/v1\/notifications(\?|$)/, (route) => {
    const read = state.readHere + state.readElsewhere;

    return route.fulfill({
      json: {
        notifications: [1, 2, 3].map((n) => notification(String(n), n <= read)),
        total: 3,
        unread: Math.max(0, state.unread - read),
        limit: 25,
        offset: 0,
      },
    });
  });

  await page.route(/\/api\/v1\/notifications\/[^/]+\/read$/, (route) => {
    state.readHere += 1;

    return route.fulfill({ status: 204, body: '' });
  });

  return state;
}

test.describe('the unread badge', () => {
  test('shows the server’s count, not one this tab worked out', async ({ page }) => {
    const state = await inbox(page);

    await page.goto('/notifications?product=atlas');

    await expect(page.getByTestId('unread-badge')).toHaveText('3');

    // Two more read in another tab, while this page sits there.
    state.readElsewhere = 2;

    // This tab reads one. A badge decremented here would now say 2; the server
    // says 0, and the badge has to agree with the server.
    await page.getByRole('button', { name: 'Mark read' }).first().click();

    await expect(page.getByTestId('unread-badge')).toHaveCount(0);
    await expect(page.getByText('0 unread of 3')).toBeVisible();
  });

  test('is absent, and asks nothing, for a session that cannot read an inbox', async ({ page }) => {
    let asked = 0;

    await page.route(/\/api\/v1\/notifications\/unread-count/, (route) => {
      asked += 1;

      return route.fulfill({ json: { unread: 7 } });
    });

    await page.route(/\/api\/v1\/me$/, (route) =>
      route.fulfill({ json: { ...SESSION, permissions: ['billing.read'] } }),
    );

    await page.goto('/?product=atlas');
    await expect(page.locator('[data-region="context-bar"]')).toBeVisible();

    await expect(page.getByTestId('unread-badge')).toHaveCount(0);
    // Not merely hidden: a refused request per page view would fill the log with
    // 403s for every session that has no inbox to read.
    expect(asked).toBe(0);
  });

  test('leads to the inbox', async ({ page }) => {
    await inbox(page);

    await page.goto('/?product=atlas');

    await page.getByTestId('unread-badge').click();

    await expect(page).toHaveURL(/\/notifications/);
    await expect(page.getByText('3 unread of 3')).toBeVisible();
  });
});

test.describe('the inbox on a phone', () => {
  test('never scrolls horizontally', async ({ page }) => {
    await inbox(page);

    await page.goto('/notifications?product=atlas');
    await expect(page.getByText('3 unread of 3')).toBeVisible();

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(overflows).toBe(false);
  });
});
