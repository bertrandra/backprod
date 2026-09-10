import type { Page } from '@playwright/test';

import { expect, stubSession, test } from './support/app';

/**
 * §37.4's chain, in a browser: **quote → order → invoice → payment →
 * activation**, and a failed payment retried.
 *
 * PHPUnit already proves the chain server-side. What it cannot prove is that a
 * *person* can walk it: that accepting a quote lands somewhere useful, that the
 * order carries the invoice it raised, that nothing is activated before the
 * money arrives, and that a declined card leads somewhere other than a dead end.
 * Those are five screens and four navigations, and every one of them is a place
 * the chain can break without a single backend test noticing.
 *
 * **The stub is a state machine, not a fixture.** Each step changes what the
 * next read returns, exactly as the backend would — accepting the quote creates
 * the order, paying it activates the subscription. A fixture that answered the
 * final state from the start would pass this suite with the middle of the chain
 * deleted.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: [
    'sales.read',
    'sales.manage',
    'billing.read',
    'billing.manage',
    'payments.read',
    'payments.manage',
    'subscription.read',
    'catalog.read',
  ],
  capabilities: [],
};

const QUOTE_ID = '44444444-4444-4444-8444-444444444444';
const ORDER_ID = '55555555-5555-4555-8555-555555555555';
const INVOICE_ID = '66666666-6666-4666-8666-666666666666';
const PAYMENT_ID = '77777777-7777-4777-8777-777777777777';

const MONEY = {
  net: { minor_units: 29_000, currency: 'EUR' },
  vat: { minor_units: 5_800, currency: 'EUR' },
  gross: { minor_units: 34_800, currency: 'EUR' },
};

interface Chain {
  accepted: boolean;
  paid: boolean;
  paymentFailed: boolean;
  retried: number;
}

/** The contract's shape, field for field — a quote has no number. */
function quote(state: Chain) {
  return {
    id: QUOTE_ID,
    status: state.accepted ? 'ACCEPTED' : 'SENT',
    // Derived server-side from the clock, and read as an answer here.
    open: !state.accepted,
    offer_version_id: 'ov-1',
    valid_until: '2126-01-01T00:00:00Z',
    customer: {},
    sent_at: '2026-01-01T10:00:00Z',
    decided_at: state.accepted ? '2026-01-02T10:00:00Z' : null,
    created_at: '2026-01-01T10:00:00Z',
    lines: [],
    ...MONEY,
  };
}

function order(state: Chain) {
  return {
    id: ORDER_ID,
    status: state.paid ? 'COMPLETED' : 'AWAITING_PAYMENT',
    quote_id: QUOTE_ID,
    offer_version_id: 'ov-1',
    // The invoice is raised at order time; the subscription waits for the money
    // (ADR-024, split fulfilment).
    invoice_id: INVOICE_ID,
    subscription_id: state.paid ? '88888888-8888-4888-8888-888888888888' : null,
    completed_at: state.paid ? '2026-01-03T10:00:00Z' : null,
    created_at: '2026-01-02T10:00:00Z',
    lines: [],
    ...MONEY,
  };
}

function invoice(state: Chain) {
  return {
    id: INVOICE_ID,
    number: '2026-000042',
    status: state.paid ? 'PAID' : 'ISSUED',
    final: true,
    subscription_id: null,
    issued_at: '2026-01-02T10:00:00Z',
    due_at: '2026-02-01T10:00:00Z',
    paid_at: state.paid ? '2026-01-03T10:00:00Z' : null,
    period_start: null,
    period_end: null,
    payment_terms: null,
    supplier: { legal_name: 'Backprod SAS' },
    customer: { legal_name: 'Acme Ltd' },
    lines: [],
    taxes: [],
    ...MONEY,
  };
}

function payment(state: Chain) {
  return {
    id: PAYMENT_ID,
    invoice_id: INVOICE_ID,
    subscription_id: null,
    provider: 'stripe',
    provider_payment_id: 'pi_1',
    status: state.paid ? 'SUCCEEDED' : 'FAILED',
    settled: state.paid,
    final: true,
    amount: MONEY.gross,
    method: 'card',
    failure_code: state.paid ? null : 'card_declined',
    failure_reason: state.paid ? null : 'The card was declined.',
    succeeded_at: state.paid ? '2026-01-03T10:00:00Z' : null,
    failed_at: state.paid ? null : '2026-01-03T09:00:00Z',
    created_at: '2026-01-03T08:59:00Z',
  };
}

function subscription(state: Chain) {
  return state.paid
    ? {
        subscription: {
          id: '88888888-8888-4888-8888-888888888888',
          status: 'ACTIVE',
          current_period_start: '2026-01-03T10:00:00Z',
          current_period_end: '2026-02-03T10:00:00Z',
          cancel_at_period_end: false,
          cancel_effective_at: null,
          terms: { commitment_months: 12, notice_days: 30 },
          offer: {
            id: 'offer-1',
            name: 'Pro, yearly',
            plan: { name: 'Pro' },
            version: { billing_period: 'MONTHLY', price: MONEY.net },
          },
        },
      }
    : { subscription: null };
}

async function chain(page: Page): Promise<Chain> {
  const state: Chain = { accepted: false, paid: false, paymentFailed: true, retried: 0 };

  // First, so everything below overrides it. Playwright matches the *most
  // recently* registered route, and a catch-all added last silently answers
  // every request in this file — which is exactly what it did on the first run.
  await page.route(/\/api\/v1\//, (route) =>
    route.fulfill({ json: { total: 0, limit: 25, offset: 0, unread: 0 } }),
  );

  // Re-registered after the catch-all above, which would otherwise answer the
  // session refresh and leave every test in this file on the sign-in form.
  // Playwright uses the most recently registered route (U9).
  await stubSession(page);

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: SESSION }));
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: SESSION.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  // Accepting answers with the **order**, not with the quote — the contract's
  // point being that acceptance produces something new.
  await page.route(/\/api\/v1\/sales\/quotes\/[^/]+\/accept$/, (route) => {
    state.accepted = true;

    return route.fulfill({ status: 201, json: order(state) });
  });

  await page.route(/\/api\/v1\/sales\/quotes(\?|$)/, (route) =>
    route.fulfill({ json: { quotes: [quote(state)], total: 1, limit: 25, offset: 0 } }),
  );

  await page.route(/\/api\/v1\/sales\/quotes\/[^/]+$/, (route) =>
    route.fulfill({ json: quote(state) }),
  );

  await page.route(/\/api\/v1\/sales\/orders(\?|$)/, (route) =>
    route.fulfill({
      json: { orders: state.accepted ? [order(state)] : [], total: state.accepted ? 1 : 0, limit: 25, offset: 0 },
    }),
  );

  await page.route(/\/api\/v1\/sales\/orders\/[^/]+$/, (route) =>
    route.fulfill({ json: order(state) }),
  );

  await page.route(/\/api\/v1\/billing\/invoices(\?|$)/, (route) =>
    route.fulfill({
      json: {
        invoices: state.accepted ? [invoice(state)] : [],
        total: state.accepted ? 1 : 0,
        limit: 25,
        offset: 0,
      },
    }),
  );

  await page.route(/\/api\/v1\/billing\/invoices\/[^/]+$/, (route) =>
    route.fulfill({ json: invoice(state) }),
  );

  await page.route(/\/api\/v1\/billing\/payments(\?|$)/, (route) =>
    route.fulfill({
      json: {
        payments: state.accepted ? [payment(state)] : [],
        total: state.accepted ? 1 : 0,
        limit: 25,
        offset: 0,
      },
    }),
  );

  // Retrying is a **new attempt**: the failed one stays failed, and the answer
  // is a fresh client secret rather than a resurrected payment.
  await page.route(/\/api\/v1\/payments\/[^/]+\/retry$/, (route) => {
    state.retried += 1;
    state.paid = true;

    return route.fulfill({ status: 201, json: { client_secret: 'pi_secret_second_attempt' } });
  });

  await page.route(/\/api\/v1\/subscription$/, (route) => route.fulfill({ json: subscription(state) }));

  await page.route(/\/api\/v1\/subscription\/entitlements$/, (route) =>
    route.fulfill({ json: { entitlements: [] } }),
  );

  await page.route(/\/api\/v1\/subscription\/schedule$/, (route) =>
    route.fulfill({ json: { if_cancelled_now: undefined } }),
  );

  return state;
}

test.describe('the §37.4 chain', () => {
  test('quote → order → invoice → payment → activation, walked by a person', async ({ page }) => {
    const state = await chain(page);

    // 1. The quote, open and actionable.
    await page.goto('/quotes?product=atlas');
    await expect(page.getByRole('button', { name: /^Accept$/ })).toBeVisible();

    // 2. Accepting produces an order, and the screen follows the person to it —
    //    they accepted in order to get somewhere.
    await page.getByRole('button', { name: /^Accept$/ }).click();
    await expect(page).toHaveURL(/\/orders/, { timeout: 10_000 });
    expect(state.accepted).toBe(true);

    // 3. The order raised an invoice and has **no subscription yet**: nothing is
    //    provisioned before the money arrives (ADR-024). The screen renders that
    //    split as a gate, so the assertion is the gate rather than a status
    //    string that could be anywhere on the page.
    await expect(page.locator(`[data-status="AWAITING_PAYMENT"]`).first()).toBeVisible({
      timeout: 10_000,
    });
    await expect(page.getByTestId('gate-invoice').first()).toBeVisible();
    await expect(page.getByTestId('gate-subscription').first()).toContainText(/not|await|pending/i);

    await page.goto('/subscription?product=atlas');
    await expect(page.getByText('No subscription')).toBeVisible();

    // 4. The invoice exists, final, with its allocated number.
    await page.goto('/invoices?product=atlas');
    await expect(page.getByTestId('invoice-number').first()).toHaveText('2026-000042');

    // 5. The payment failed, and says why in words a person can act on.
    await page.goto('/payments?product=atlas');
    await expect(page.getByTestId('failure')).toContainText('card_declined');

    // 6. Retrying is a new attempt, and the money arriving is what activates.
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByTestId('new-attempt')).toContainText('new');
    expect(state.retried).toBe(1);

    await page.goto('/subscription?product=atlas');
    await expect(page.getByTestId('subscription-status')).toHaveText('ACTIVE', {
      timeout: 10_000,
    });

    // And the two facts §13.1 keeps apart are both on screen, unmerged.
    await expect(page.getByTestId('periodicity')).toContainText('monthly');
    await expect(page.getByTestId('commitment')).toContainText('12 months');
  });

  test('nothing is activated before the money arrives', async ({ page }) => {
    await chain(page);

    await page.goto('/quotes?product=atlas');
    await page.getByRole('button', { name: /^Accept$/ }).click();
    await expect(page).toHaveURL(/\/orders/, { timeout: 10_000 });

    // The order exists and the invoice is raised — and the subscription is not.
    // This is the split ADR-024 describes, and the assertion that would fail if
    // a screen ever activated optimistically.
    await expect(page.getByTestId('payment-gate').first()).toBeVisible({ timeout: 10_000 });

    await page.goto('/subscription?product=atlas');
    await expect(page.getByText('No subscription')).toBeVisible();
    await expect(page.getByTestId('subscription-status')).toHaveCount(0);
  });

  test('the credential for the retry never reaches the page', async ({ page }) => {
    await chain(page);

    await page.goto('/quotes?product=atlas');
    await page.getByRole('button', { name: /^Accept$/ }).click();
    await expect(page).toHaveURL(/\/orders/, { timeout: 10_000 });

    await page.goto('/payments?product=atlas');
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByTestId('new-attempt')).toBeVisible();

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
