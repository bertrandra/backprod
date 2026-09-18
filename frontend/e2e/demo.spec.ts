import AxeBuilder from '@axe-core/playwright';
// The raw `test`, like `storefront.spec.ts`: this page is read with no session.
import { expect, test } from '@playwright/test';

/**
 * The demonstration page, in a browser: reachable with no session at all,
 * and either the whole picture or one sentence, by the platform's switch.
 */
const NO_SESSION = {
  status: 401,
  json: { error: { code: 'UNAUTHENTICATED', message: 'Authentication is required.', details: {}, request_id: 'r' } },
};

const CONTENTS = {
  products: [
    {
      code: 'atlas',
      name: 'Atlas',
      offers: [{ code: 'pro-monthly', name: 'Pro, monthly', plan: 'pro', billing_period: 'MONTHLY', price: { minor_units: 4940, currency: 'EUR' }, publicly_listed: true }],
    },
  ],
  tenants: [
    {
      slug: 'acme',
      name: 'Acme Ltd',
      is_default: true,
      join_policy: 'OPEN',
      products: ['atlas'],
      subscriptions: [{ product: 'atlas', offer: 'Pro, monthly', plan: 'Pro', status: 'ACTIVE' }],
      members: [{ display_name: 'Ada Lovelace', email: 'ada@demo.test', roles: ['TENANT_ADMIN'] }],
    },
    { slug: 'globex', name: 'Globex SA', is_default: false, join_policy: 'OPEN', products: ['atlas'], subscriptions: [], members: [] },
  ],
};

test.describe('the demonstration page', () => {
  test.beforeEach(async ({ page }) => {
    await page.route(/\/api\/v1\//, (route) => route.fulfill({ status: 404, json: { error: { code: 'NOT_FOUND' } } }));
    await page.route('**/api/v1/auth/refresh', (route) => route.fulfill(NO_SESSION));
  });

  test('shows the whole picture to a stranger, with a link to each organisation', async ({ page }) => {
    await page.route(/\/api\/v1\/public\/demo$/, (route) => route.fulfill({ json: CONTENTS }));

    await page.goto('/demo');

    await expect(page.getByRole('heading', { name: 'What this platform hosts' })).toBeVisible();
    await expect(page.locator('[data-demo-product="atlas"]')).toContainText('Pro, monthly');
    await expect(page.locator('[data-demo-tenant="acme"]')).toContainText('TENANT_ADMIN');
    await expect(page.getByTestId('demo-home-acme')).toHaveAttribute('href', '/');
    await expect(page.getByTestId('demo-home-globex')).toHaveAttribute('href', '/globex/');

    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(results.violations).toEqual([]);
  });

  test('says there is none while the switch is off', async ({ page }) => {
    await page.route(/\/api\/v1\/public\/demo$/, (route) =>
      route.fulfill({ status: 404, json: { error: { code: 'DEMO_PAGE_OFF', message: 'No.', details: {}, request_id: 'r' } } }),
    );

    await page.goto('/demo');

    await expect(page.getByTestId('demo-off')).toBeVisible();
    await expect(page.getByText('No demonstration page')).toBeVisible();
  });
});
