import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * U7's exit criteria, in a browser.
 *
 *   - **a closed period is visibly closed and offers no path to reopen** —
 *     asserted against the whole rendered page rather than against a component
 *     that was told not to render a button;
 *   - **the calculator shows the rule, rate and regime behind its answer** —
 *     and the legal mention, which is the one sentence on it with legal effect;
 *   - **closing states what becomes impossible**, and never claims the period is
 *     closed before the server says so. The stub holds the close open, so the
 *     window an optimistic implementation would fill is real time.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['tax.read', 'tax.manage'],
  capabilities: [],
};

const OPEN_ID = '44444444-4444-4444-8444-444444444444';
const CLOSED_ID = '55555555-5555-4555-8555-555555555555';

const DAY = 86_400_000;
const day = (offset: number): string =>
  new Date(Date.now() + offset * DAY).toISOString().slice(0, 10);

const ENDED = {
  id: OPEN_ID,
  jurisdiction: 'FR',
  period_kind: 'QUARTER',
  starts_on: day(-120),
  ends_on: day(-30),
  status: 'OPEN',
  closed_at: null,
};

const CLOSED = {
  ...ENDED,
  id: CLOSED_ID,
  status: 'CLOSED',
  closed_at: '2026-04-02T08:00:00Z',
};

const DECLARATION = {
  id: '66666666-6666-4666-8666-666666666666',
  period_id: CLOSED_ID,
  currency: 'EUR',
  total_base: 100_000,
  total_vat: 20_000,
  transaction_count: 4,
  breakdown: [
    { regime: 'STANDARD', rate: 2000, currency: 'EUR', base: 100_000, vat: 20_000, count: 4 },
  ],
};

/** Deliberately different from the declaration: a recount would show these. */
const RECOUNTED = {
  currency: 'EUR',
  currencies: ['EUR'],
  total_base: 90_000,
  total_vat: 18_000,
  transaction_count: 5,
  breakdown: [
    { regime: 'STANDARD', rate: 2000, currency: 'EUR', base: 90_000, vat: 18_000, count: 5 },
  ],
};

async function tax(page: Page, session: Record<string, unknown> = SESSION) {
  const state = { closed: false, closeRequests: 0 };

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: session }));

  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: session.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  await page.route(/\/api\/v1\/tax\/profile$/, (route) =>
    route.fulfill({
      json: {
        profile: {
          tenant_id: session.tenant_id,
          customer_kind: 'B2B',
          country_code: 'FR',
          taxable_person: true,
          location_evidence: {},
          vat_number: 'FR12345678901',
          // Asked, and no answer came back. Not a refusal.
          vat_number_status: 'UNAVAILABLE',
          vat_number_verified_at: null,
          vat_number_country: 'FR',
          reverse_charge_available: false,
        },
      },
    }),
  );

  await page.route(/\/api\/v1\/tax\/rates(\?|$)/, (route) =>
    route.fulfill({
      json: {
        on: '2026-03-15T00:00:00Z',
        rates: [
          {
            country_code: 'FR',
            rate_kind: 'STANDARD',
            basis_points: 2000,
            valid_from: '2014-01-01T00:00:00Z',
            valid_until: null,
            source: 'seed:eu-27',
          },
        ],
      },
    }),
  );

  await page.route(/\/api\/v1\/tax\/calculate$/, (route) =>
    route.fulfill({
      json: {
        calculation: {
          rule_id: 'eu.b2b.reverse_charge',
          regime: 'REVERSE_CHARGE',
          country_of_taxation: 'DE',
          rate_basis_points: 0,
          taxable_base: 12_900,
          vat_amount: 0,
          currency: 'EUR',
          reverse_charge: true,
          customer_tax_status: 'VERIFIED_BUSINESS',
          legal_mention: 'Autoliquidation — article 196 de la directive 2006/112/CE',
          reasons: ['The customer is a verified business in another member state.'],
        },
      },
    }),
  );

  await page.route(/\/api\/v1\/tax\/transactions(\?|$)/, (route) =>
    route.fulfill({ json: { transactions: [], total: 0, limit: 25, offset: 0 } }),
  );

  await page.route(/\/api\/v1\/tax\/reports\/[^/]+\/close$/, (route) => {
    state.closeRequests += 1;

    // Held open, so the window an optimistic close would have filled with an
    // invented declaration is real time rather than a mocked promise.
    return new Promise((resolve) => {
      setTimeout(() => {
        state.closed = true;
        resolve(route.fulfill({ json: { declaration: { ...DECLARATION, period_id: OPEN_ID } } }));
      }, 1_200);
    });
  });

  await page.route(/\/api\/v1\/tax\/reports\/[^/]+$/, (route) => {
    const url = route.request().url();

    if (url.includes(CLOSED_ID)) {
      return route.fulfill({
        json: { period: CLOSED, totals: RECOUNTED, declaration: DECLARATION },
      });
    }

    return route.fulfill({
      json: state.closed
        ? {
            period: { ...ENDED, status: 'CLOSED', closed_at: new Date().toISOString() },
            totals: RECOUNTED,
            declaration: { ...DECLARATION, period_id: OPEN_ID },
          }
        : {
            period: ENDED,
            totals: { ...RECOUNTED, total_base: 40_000, total_vat: 8_000 },
            declaration: null,
          },
    });
  });

  await page.route(/\/api\/v1\/tax\/reports$/, (route) =>
    route.fulfill({ json: { periods: [ENDED, CLOSED] } }),
  );

  return state;
}

test.describe('a closed period', () => {
  test('is visibly closed and offers no path to reopen', async ({ page }) => {
    await tax(page);

    await page.goto(`/tax/reports?product=atlas&selected=${CLOSED_ID}`);

    await expect(page.getByTestId('closed-at')).toContainText('Closed on');
    await expect(page.getByTestId('no-reopen')).toContainText('cannot be reopened');

    // Nothing anywhere on the page offers a way back — not a disabled control,
    // not a menu item, not a link.
    await expect(page.getByRole('button', { name: /reopen|re-open|unlock/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /reopen|re-open/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /close the period|close it/i })).toHaveCount(0);
  });

  test('reports the declared figures and not a recount', async ({ page }) => {
    await tax(page);

    await page.goto(`/tax/reports?product=atlas&selected=${CLOSED_ID}`);

    await expect(page.getByTestId('declaration')).toBeVisible();
    await expect(page.locator('[data-minor-units="100000"]').first()).toBeVisible();
    // The number a screen that recounted would show instead.
    await expect(page.locator('[data-minor-units="90000"]')).toHaveCount(0);
  });
});

test.describe('closing a period', () => {
  test('names what becomes impossible, and waits for the server', async ({ page }) => {
    const state = await tax(page);

    await page.goto(`/tax/reports?product=atlas&selected=${OPEN_ID}`);

    await page.getByRole('button', { name: 'Close the period…' }).click();

    const confirmation = page.getByTestId('close-confirmation');
    await expect(confirmation).toContainText('none of it can be undone');
    await expect(confirmation).toContainText('can never be reopened');
    await expect(confirmation).not.toContainText('Are you sure');

    await page.getByRole('button', { name: 'Close it permanently' }).click();

    // In flight: nothing claims a declaration exists, because its figures are
    // the server's to compute and this client cannot know them.
    //
    // Asserted on the *state* rather than on a label. The button used to rename
    // itself to "Working…" while a write was in flight, which lost the person
    // what they had pressed; it now keeps its label, turns a spinner and says
    // `aria-busy`, which is what a screen reader needs and what this checks.
    await expect(page.getByRole('button', { name: 'Close it permanently' })).toHaveAttribute(
      'aria-busy',
      'true',
    );
    await expect(page.getByTestId('declaration')).toHaveCount(0);

    await expect(page.getByTestId('declaration')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('no-reopen')).toBeVisible();
    expect(state.closeRequests).toBe(1);
  });
});

test.describe('the calculator', () => {
  test('shows the rule, rate and regime behind its answer', async ({ page }) => {
    await tax(page);

    await page.goto('/tax/rates?product=atlas');

    await page.getByLabel('Amount').fill('129');
    await page.getByRole('button', { name: 'Explain it' }).click();

    const calculation = page.getByTestId('calculation');
    await expect(calculation).toBeVisible();
    await expect(page.getByTestId('rule-id')).toHaveText('eu.b2b.reverse_charge');
    await expect(page.getByTestId('regime')).toHaveText('REVERSE CHARGE');
    await expect(page.getByTestId('calculated-rate')).toHaveText('0%');
    await expect(calculation).toContainText('taxed in DE');
    await expect(page.getByTestId('reasons')).toContainText('verified business');
    await expect(page.getByTestId('legal-mention')).toContainText('Autoliquidation');
  });
});

test.describe('the tax profile', () => {
  test('keeps an unavailable check distinct from a refusal', async ({ page }) => {
    await tax(page);

    await page.goto('/tax?product=atlas');

    await expect(page.getByTestId('vat-status')).toHaveText('Unavailable');
    await expect(page.getByTestId('status-explanation')).toContainText('gave no answer');
    // Fail-closed: unproved is not trusted (R8).
    await expect(page.getByTestId('reverse-charge')).toContainText('not available');
  });
});

test.describe('tax on a phone', () => {
  test('never scrolls horizontally', async ({ page }) => {
    await tax(page);
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto(`/tax/reports?product=atlas&selected=${CLOSED_ID}`);
    await expect(page.getByTestId('declaration')).toBeVisible();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );

    expect(overflow).toBeLessThanOrEqual(0);
  });
});
