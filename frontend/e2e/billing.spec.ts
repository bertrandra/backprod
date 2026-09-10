import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * U6's exit criteria, in a browser.
 *
 *   - **issuing an invoice shows a real pending state and never a provisional
 *     number** — the stub holds the response open, so the window where an
 *     optimistic implementation would have shown an invented number is real
 *     time rather than a mocked promise;
 *   - **a failed payment leads to retry, and the UI makes clear it is a new
 *     attempt**;
 *   - **money is rendered from `minor_units` throughout** — asserted against the
 *     integers themselves, which every amount carries in the DOM.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['billing.read', 'billing.manage', 'payments.read', 'payments.manage'],
  capabilities: [],
};

const INVOICE_ID = '44444444-4444-4444-8444-444444444444';
const NUMBER = '2026-000042';

const MONEY = {
  net: { minor_units: 2900, currency: 'EUR' },
  vat: { minor_units: 160, currency: 'EUR' },
  gross: { minor_units: 3060, currency: 'EUR' },
};

function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: INVOICE_ID,
    number: NUMBER,
    status: 'ISSUED',
    final: true,
    subscription_id: null,
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
    ...MONEY,
    ...overrides,
  };
}

async function billing(page: Page, session: Record<string, unknown> = SESSION) {
  const state = { issued: false, retried: 0 };

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: session }));

  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: session.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  await page.route(/\/api\/v1\/billing\/invoices(\?|$)/, (route) => {
    if (route.request().method() === 'POST') {
      state.issued = true;

      // Held for a beat, so the pending state is a real window rather than an
      // instant that no assertion could land in.
      return new Promise((resolve) => {
        setTimeout(() => {
          resolve(route.fulfill({ status: 201, json: invoice() }));
        }, 1_200);
      });
    }

    return route.fulfill({
      json: {
        invoices: state.issued ? [invoice()] : [],
        total: state.issued ? 1 : 0,
        limit: 25,
        offset: 0,
      },
    });
  });

  await page.route(/\/api\/v1\/billing\/payments(\?|$)/, (route) =>
    route.fulfill({
      json: {
        payments: [
          {
            id: 'pay-1',
            invoice_id: INVOICE_ID,
            subscription_id: null,
            provider: 'stripe',
            provider_payment_id: 'pi_1',
            status: 'FAILED',
            settled: false,
            final: true,
            amount: MONEY.gross,
            method: 'card',
            failure_code: 'card_declined',
            failure_reason: 'The card was declined.',
            succeeded_at: null,
            failed_at: '2026-01-01T10:00:00Z',
            created_at: '2026-01-01T09:59:00Z',
          },
        ],
        total: 1,
        limit: 25,
        offset: 0,
      },
    }),
  );

  await page.route(/\/api\/v1\/payments\/[^/]+\/retry$/, (route) => {
    state.retried += 1;

    return route.fulfill({ status: 201, json: { client_secret: 'pi_secret_new_attempt' } });
  });

  return state;
}

test.describe('issuing an invoice', () => {
  test('shows a pending state and never a provisional number', async ({ page }) => {
    await billing(page);

    await page.goto('/invoices?product=atlas');
    await page.getByRole('button', { name: 'Issue an invoice…' }).click();
    await page.getByRole('button', { name: 'Issue it' }).click();

    // The window an optimistic implementation would have filled with an
    // invented number.
    await expect(page.getByTestId('issuing')).toBeVisible();
    await expect(page.getByTestId('invoice-number')).toHaveCount(0);
    expect(await page.locator('body').textContent()).not.toContain(NUMBER);

    // And then the number the database allocated.
    await expect(page.getByTestId('invoice-number')).toHaveText(NUMBER, { timeout: 10_000 });
  });
});

test.describe('a failed payment', () => {
  test('is retried as a new attempt, and says so', async ({ page }) => {
    const state = await billing(page);

    await page.goto('/payments?product=atlas');
    await expect(page.getByTestId('failure')).toContainText('card_declined');

    await page.getByRole('button', { name: 'Try again' }).click();

    await expect(page.getByTestId('new-attempt')).toContainText('new');
    await expect(page.getByTestId('new-attempt')).toContainText('stays failed');
    expect(state.retried).toBe(1);

    // The credential goes to the provider's SDK and nowhere else.
    const leaked = await page.evaluate(() => ({
      body: document.body.textContent ?? '',
      local: JSON.stringify(window.localStorage),
      session: JSON.stringify(window.sessionStorage),
      url: window.location.href,
    }));

    expect(leaked.body).not.toContain('pi_secret');
    expect(leaked.local).not.toContain('pi_secret');
    expect(leaked.session).not.toContain('pi_secret');
    expect(leaked.url).not.toContain('pi_secret');
  });
});

test.describe('money', () => {
  test('is rendered from minor units, with no float anywhere', async ({ page }) => {
    await billing(page);

    await page.goto('/payments?product=atlas');

    const amounts = page.locator('[data-minor-units]');
    await expect(amounts.first()).toBeVisible();

    // Every rendered amount carries the integer it came from, and every one of
    // those integers is an integer.
    const values = await amounts.evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('data-minor-units') ?? ''),
    );

    expect(values.length).toBeGreaterThan(0);

    for (const value of values) {
      expect(value).toMatch(/^-?\d+$/);
    }
  });
});

test.describe('billing on a phone', () => {
  test('never scrolls horizontally', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await billing(page);

    await page.goto('/payments?product=atlas');
    await expect(page.getByTestId('failure')).toBeVisible();

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(overflows).toBe(false);
  });
});
