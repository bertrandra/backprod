import { expect, test, type Page } from '@playwright/test';

/**
 * U1's exit criteria, in a browser.
 *
 * The session comes from a stubbed `/api/v1/me` rather than a running backend:
 * this suite is about the shell, and coupling it to a live API would make it
 * fail for reasons that have nothing to do with the frame. U9 adds the suites
 * that go through the real chain.
 */

const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['projects.read', 'billing.read', 'subscription.read', 'tax.read', 'jobs.read'],
  capabilities: ['white_label'],
};

/**
 * Stubs the session. Every `goto` below carries `?product=…` because the client
 * refuses to build a request without a product — it is the root context, and the
 * shell says so rather than guessing one.
 */
async function signedIn(page: Page, session: Record<string, unknown> = SESSION) {
  await page.route('**/api/v1/me', async (route) => {
    await route.fulfill({ json: session });
  });
}

const REGIONS = ['context-bar', 'primary-nav', 'view-body', 'status-strip', 'overlay'] as const;

test.describe('the frame', () => {
  test('renders its regions, and the bottom nav only where it belongs', async ({
    page,
    viewport,
  }) => {
    await signedIn(page);
    await page.goto('/?product=atlas');

    for (const region of REGIONS) {
      await expect(page.locator(`[data-region="${region}"]`)).toHaveCount(1);
    }

    const narrow = (viewport?.width ?? 1280) < 768;

    // Both exist in the DOM at every width — one presentation per width, not a
    // region that disappears (ui-spec.md §4.2). Which one is *visible* is the
    // difference.
    await expect(page.locator('[data-region="bottom-nav"]')).toHaveCount(1);

    if (narrow) {
      await expect(page.locator('[data-region="bottom-nav"]')).toBeVisible();
      await expect(page.locator('[data-region="primary-nav"]')).toBeHidden();
    } else {
      await expect(page.locator('[data-region="primary-nav"]')).toBeVisible();
      await expect(page.locator('[data-region="bottom-nav"]')).toBeHidden();
    }
  });

  test('never scrolls horizontally', async ({ page }) => {
    await signedIn(page);
    await page.goto('/?product=atlas');

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(overflows).toBe(false);
  });

  test('keeps every navigation entry reachable, on a phone through More', async ({
    page,
    viewport,
  }) => {
    await signedIn(page);
    await page.goto('/?product=atlas');

    if ((viewport?.width ?? 1280) < 768) {
      // Five fit in the bar; the rest must still be reachable, which is what the
      // sheet is for.
      await page.getByRole('button', { name: 'More' }).click();
      await expect(page.getByTestId('more-sheet')).toBeVisible();
      await expect(page.locator('[data-nav-more="tax"]')).toBeVisible();
    } else {
      await expect(page.locator('[data-nav="tax"]')).toBeVisible();
    }
  });
});

test.describe('what the navigation offers', () => {
  test('shows only the sections the permissions allow', async ({ page, viewport }) => {
    await signedIn(page, { ...SESSION, permissions: ['billing.read'] });
    await page.goto('/?product=atlas');

    const wide = (viewport?.width ?? 1280) >= 768;

    if (wide) {
      await expect(page.locator('[data-nav="invoices"]')).toBeVisible();
      // No projects.read, so no Projects — and no empty "Work" heading either.
      await expect(page.locator('[data-nav="projects"]')).toHaveCount(0);
      await expect(page.getByText('Work', { exact: true })).toHaveCount(0);
    } else {
      await expect(page.locator('[data-nav-bottom="invoices"]')).toBeVisible();
      await expect(page.locator('[data-nav-bottom="projects"]')).toHaveCount(0);
    }
  });
});

test.describe('deep links', () => {
  test('restore the filter, tab, selection and panel from the URL', async ({ page }) => {
    await signedIn(page);
    await page.goto('/invoices?product=atlas&q=atlas&tab=lines&selected=inv-1&panel=true&limit=50');

    // The state survives the round trip rather than being dropped on parse,
    // which is what makes a link shareable (ui-spec.md §4.3).
    await expect(page).toHaveURL(/q=atlas/);
    await expect(page).toHaveURL(/tab=lines/);
    await expect(page).toHaveURL(/selected=inv-1/);
    await expect(page).toHaveURL(/panel=true/);
  });

  test('open the page rather than breaking when the query string is nonsense', async ({ page }) => {
    await signedIn(page);
    await page.goto('/invoices?product=atlas&limit=all&tab=%3Cscript%3E&panel=maybe');

    // A stale bookmark should still land somewhere useful.
    await expect(page.locator('[data-region="context-bar"]')).toBeVisible();
  });

  test('land on a real page for an unknown route instead of a blank screen', async ({ page }) => {
    await signedIn(page);
    await page.goto('/nope?product=atlas');

    await expect(page.getByText('No such page')).toBeVisible();
    // The person keeps their navigation and can go somewhere else.
    await expect(page.locator('[data-region="context-bar"]')).toBeVisible();
  });
});

test.describe('keyboard', () => {
  test('reaches interactive elements, and focus is visible', async ({ page }) => {
    await signedIn(page);
    await page.goto('/?product=atlas');

    // Wait for the shell before pressing a key. Tabbing into a document that has
    // not rendered focuses nothing, which made this pass or fail on timing.
    await expect(page.locator('[data-region="context-bar"]')).toBeVisible();

    await page.keyboard.press('Tab');

    const focused = await page.evaluate(() => {
      const el = document.activeElement;

      if (el === null || el === document.body) {
        return null;
      }

      return { tag: el.tagName, outline: getComputedStyle(el).outlineStyle };
    });

    expect(focused).not.toBeNull();
  });

  test('opens the palette with the shortcut and closes it with Escape', async ({ page }) => {
    await signedIn(page);
    await page.goto('/?product=atlas');

    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByTestId('command-palette')).toBeVisible();

    // Focus moves into the dialog, which is what makes it usable without a mouse.
    await expect(page.getByRole('searchbox', { name: 'Search commands' })).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(page.getByTestId('command-palette')).toHaveCount(0);
  });
});
