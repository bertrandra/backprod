import type { Page } from '@playwright/test';

import { expect, test } from './support/app';

/**
 * U5's exit criterion that only a browser can judge: **a checkout whose
 * connection drops leaves a findable order, and the UI leads back to it.**
 *
 * "Dropped" is simulated the way it actually happens — the tab goes away. The
 * page is reloaded mid-checkout, and what matters is that everything except the
 * `client_secret` is still there, reachable both by the URL and from the order
 * list. Nothing is recovered, because nothing was only in the tab.
 */
const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['catalog.read', 'catalog.manage', 'billing.manage', 'billing.pay', 'sales.read', 'sales.manage'],
  capabilities: [],
};

const PLAN = { id: '44444444-4444-4444-8444-444444444444', code: 'PRO', name: 'Pro', rank: 20 };
const OFFER_ID = '55555555-5555-4555-8555-555555555555';
const ORDER_ID = '66666666-6666-4666-8666-666666666666';

const OFFER = {
  id: OFFER_ID,
  code: 'pro-monthly',
  name: 'Pro monthly',
  plan: PLAN,
  version: {
    id: '77777777-7777-4777-8777-777777777777',
    version: 1,
    billing_period: 'MONTHLY',
    price: { minor_units: 2900, currency: 'EUR' },
    valid_from: '2026-01-01T00:00:00Z',
    valid_until: null,
    grants: [],
  },
};

const MONEY = {
  net: { minor_units: 2900, currency: 'EUR' },
  vat: { minor_units: 580, currency: 'EUR' },
  gross: { minor_units: 3480, currency: 'EUR' },
};

/**
 * A backend that remembers the order across reloads, which is the whole point:
 * the order lives on the server, not in the tab.
 */
async function commerce(page: Page, session: Record<string, unknown> = SESSION) {
  const state = { opened: false, secretsIssued: 0 };

  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: session }));

  await page.route(/\/api\/v1\/plans$/, (route) => route.fulfill({ json: { plans: [PLAN] } }));

  // The product switcher reads this. Left unstubbed it fell through to the dev
  // proxy and refused, which is how the doubled API prefix finally showed up in
  // a passing run — worth stubbing so the switcher is actually exercised.
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({
      json: { products: [{ id: session.product_id, code: 'atlas', name: 'Atlas' }] },
    }),
  );

  await page.route(/\/api\/v1\/offers(\?|$)/, (route) =>
    route.fulfill({ json: { offers: [OFFER] } }),
  );

  await page.route(/\/api\/v1\/products\/[^/]+\/catalog/, (route) =>
    route.fulfill({
      json: {
        product: { id: session.product_id, code: 'atlas', name: 'Atlas' },
        features: [],
        configuration: {},
      },
    }),
  );

  await page.route(/\/api\/v1\/checkout\/sessions$/, (route) => {
    state.opened = true;
    state.secretsIssued += 1;

    return route.fulfill({
      status: 201,
      json: {
        session: {
          id: ORDER_ID,
          order_id: ORDER_ID,
          status: 'AWAITING_PAYMENT',
          invoice_id: 'invoice-1',
          subscription_id: null,
          payment_id: 'payment-1',
          payment_status: 'PENDING',
          // Returned exactly once, on opening. The read below has no such field.
          client_secret: 'pi_secret_should_never_be_stored',
          ...MONEY,
        },
      },
    });
  });

  await page.route(/\/api\/v1\/checkout\/sessions\/[^/]+$/, (route) =>
    route.fulfill({
      json: {
        session: {
          id: ORDER_ID,
          order_id: ORDER_ID,
          status: 'AWAITING_PAYMENT',
          invoice_id: 'invoice-1',
          subscription_id: null,
          payment_id: 'payment-1',
          payment_status: 'PENDING',
          ...MONEY,
        },
      },
    }),
  );

  await page.route(/\/api\/v1\/sales\/orders(\?|$)/, (route) =>
    route.fulfill({
      json: {
        orders: state.opened
          ? [
              {
                id: ORDER_ID,
                status: 'AWAITING_PAYMENT',
                quote_id: null,
                offer_version_id: OFFER.version.id,
                subscription_id: null,
                invoice_id: 'invoice-1',
                completed_at: null,
                created_at: '2026-01-01T10:00:00Z',
                lines: [],
                ...MONEY,
              },
            ]
          : [],
        total: state.opened ? 1 : 0,
        limit: 25,
        offset: 0,
      },
    }),
  );

  return state;
}

test.describe('a checkout whose connection drops', () => {
  test('leaves a findable order, and the UI leads back to it', async ({ page }) => {
    const state = await commerce(page);

    await page.goto('/catalogue?product=atlas');
    await page.getByRole('button', { name: 'Buy for the organisation' }).click();
    // Buying pays on the catalogue (ADR-048): the secret is offered there and
    // never carried to the order page. With no provider stubbed, the pay step
    // is only the link on.
    await page.getByTestId('continue-to-order').click();

    await expect(page).toHaveURL(new RegExp(`/checkout/${ORDER_ID}`));
    await expect(page.getByTestId('checkout-status')).toHaveText('AWAITING PAYMENT');
    expect(state.secretsIssued).toBe(1);

    // The tab goes away and comes back — which is what "the connection dropped"
    // looks like from the person's side.
    await page.reload();

    // Everything is still there, read back from the id in the URL.
    await expect(page.getByTestId('checkout-status')).toHaveText('AWAITING PAYMENT');
    await expect(page.getByTestId('step-invoice')).toContainText('invoice-1');
    // And no second secret was issued: the reload read the order, it did not
    // reopen a session.
    expect(state.secretsIssued).toBe(1);

    // The order is findable the other way too.
    await page.getByRole('link', { name: 'All orders' }).click();
    await expect(page).toHaveURL(/\/orders/);
    await expect(page.locator(`[data-order="${ORDER_ID}"]`)).toBeVisible();

    // …and leads back.
    await page.getByRole('link', { name: 'Open the checkout for this order' }).click();
    await expect(page).toHaveURL(new RegExp(`/checkout/${ORDER_ID}`));
  });

  test('never leaves the client secret anywhere a reload could find it', async ({ page }) => {
    await commerce(page);

    await page.goto('/catalogue?product=atlas');
    await page.getByRole('button', { name: 'Buy for the organisation' }).click();
    // Buying pays on the catalogue (ADR-048): the secret is offered there and
    // never carried to the order page. With no provider stubbed, the pay step
    // is only the link on.
    await page.getByTestId('continue-to-order').click();
    await expect(page.getByTestId('checkout-status')).toBeVisible();

    // Not in storage, not in the URL, not in the page.
    const stored = await page.evaluate(() => ({
      local: JSON.stringify(window.localStorage),
      session: JSON.stringify(window.sessionStorage),
      url: window.location.href,
      body: document.body.textContent ?? '',
    }));

    expect(stored.local).not.toContain('pi_secret');
    expect(stored.session).not.toContain('pi_secret');
    expect(stored.url).not.toContain('pi_secret');
    expect(stored.body).not.toContain('pi_secret');
  });
});

/**
 * A Stripe.js that is honestly not one, served where the real one would load
 * from. It satisfies what @stripe/react-stripe-js checks for, mounts a box
 * where the Payment Element would be, and `confirmPayment` resolves — the
 * browser's word, which the page must not read as a status.
 */
async function stubStripeJs(page: Page): Promise<void> {
  // Whichever URL the SDK asks for: `/v3` or `/<release-train>/stripe.js`.
  // Matching only `/v3` let the real Stripe.js load under some runs — an
  // iframe with a fake secret, and a Pay button that never came back.
  await page.route(/^https:\/\/js\.stripe\.com\/(v3\/?|[a-z]+\/stripe\.js)(\?.*)?$/, (route) =>
    route.fulfill({
      contentType: 'application/javascript',
      body: `
        window.Stripe = function () {
          const element = {
            mount(node) { const box = document.createElement('div'); box.setAttribute('data-testid', 'fake-payment-element'); box.textContent = 'Card'; node.appendChild(box); },
            unmount() {}, destroy() {}, on() {}, off() {}, once() {}, update() {}, collapse() {}, focus() {}, blur() {}, clear() {},
          };
          return {
            elements() { return { create() { return element; }, getElement() { return null; }, update() {}, fetchUpdates() { return Promise.resolve({}); }, submit() { return Promise.resolve({}); }, on() {}, off() {} }; },
            createToken() {}, createPaymentMethod() {}, confirmCardPayment() {},
            confirmPayment() { return Promise.resolve({ paymentIntent: { status: 'succeeded' } }); },
            _registerWrapper() {},
          };
        };
      `,
    }),
  );
}

test.describe('paying where the secret was born', () => {
  test("pays through the provider's form when it has one, then lands on the order", async ({ page }) => {
    await commerce(page);
    await stubStripeJs(page);

    // A provider with a form: the session names it, and the catalogue mounts
    // it (ADR-048). Registered after `commerce`, so it wins.
    await page.route(/\/api\/v1\/checkout\/sessions$/, (route) =>
      route.fulfill({
        status: 201,
        json: {
          session: {
            id: ORDER_ID,
            order_id: ORDER_ID,
            status: 'AWAITING_PAYMENT',
            invoice_id: 'invoice-1',
            subscription_id: null,
            payment_id: 'payment-1',
            payment_status: 'PENDING',
            client_secret: 'pi_1_secret_x',
            payment_provider: { name: 'stripe', publishable_key: 'pk_test_e2e', sandbox: true },
            ...MONEY,
          },
        },
      }),
    );

    await page.goto('/catalogue?product=atlas');
    await page.getByRole('button', { name: 'Buy for the organisation' }).click();

    // Stripe's form, in the page, with the amount the server named — and the
    // sandbox said out loud.
    await expect(page.getByTestId('stripe-form')).toBeVisible();
    await expect(page.getByTestId('sandbox-band')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Pay/ })).toContainText('34');

    // Enabled once Stripe.js has loaded — the fake one, from the route above.
    await expect(page.getByRole('button', { name: /^Pay/ })).toBeEnabled();
    await page.getByRole('button', { name: /^Pay/ }).click();

    // Whatever the form said, the status page is where the answer is read.
    await expect(page).toHaveURL(new RegExp(`/checkout/${ORDER_ID}`));
    // The secret went to the provider and nowhere the page keeps.
    expect(await page.evaluate(() => JSON.stringify(window.localStorage))).not.toContain('pi_1_secret');
  });
});

test.describe('the payment gate', () => {
  test('shows invoiced and not-yet-provisioned as two different things', async ({ page }) => {
    await commerce(page);

    await page.goto('/catalogue?product=atlas');
    await page.getByRole('button', { name: 'Buy for the organisation' }).click();
    // Buying pays on the catalogue (ADR-048): the secret is offered there and
    // never carried to the order page. With no provider stubbed, the pay step
    // is only the link on.
    await page.getByTestId('continue-to-order').click();

    await expect(page.getByTestId('step-invoice')).toContainText('invoice-1');
    await expect(page.getByTestId('step-subscription')).toContainText('Not started');
  });
});

test.describe('every request the application makes', () => {
  test('carries the API prefix exactly once', async ({ page }) => {
    // The guard for a defect that shipped from U0 to U5 unseen: the contract's
    // paths already begin with /api/v1 and the client prepended it again, so
    // every live request went to /api/v1/api/v1/… and would have 404'd against
    // the real backend.
    //
    // Neither test layer could see it. The unit tests replace the client, so
    // they never compose a URL; and the route patterns in these specs match a
    // doubled path just as happily as a correct one. Only a vite proxy error in
    // an otherwise passing run gave it away. This watches the real thing.
    const apiRequests: string[] = [];

    page.on('request', (request) => {
      if (request.url().includes('/api/')) {
        apiRequests.push(request.url());
      }
    });

    await commerce(page);

    await page.goto('/catalogue?product=atlas');
    await expect(page.getByText('Pro monthly')).toBeVisible();
    await page.getByRole('button', { name: 'Buy for the organisation' }).click();
    // Buying pays on the catalogue (ADR-048): the secret is offered there and
    // never carried to the order page. With no provider stubbed, the pay step
    // is only the link on.
    await page.getByTestId('continue-to-order').click();
    await expect(page.getByTestId('checkout-status')).toBeVisible();

    expect(apiRequests.length).toBeGreaterThan(0);

    for (const url of apiRequests) {
      expect(url).not.toContain('/api/v1/api/v1');
      expect(new URL(url).pathname).toMatch(/^\/api\/v1\/[^/]/);
    }
  });
});

test.describe('the catalogue on a phone', () => {
  test('never scrolls horizontally', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await commerce(page);

    await page.goto('/catalogue?product=atlas');
    await expect(page.getByText('Pro monthly')).toBeVisible();

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );

    expect(overflows).toBe(false);
  });
});
