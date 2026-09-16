import AxeBuilder from '@axe-core/playwright';
// The raw `test`, like `sign-in.spec.ts`: `support/app` hands every other spec a
// session, and this is the spec about a person who has never had one.
import { expect, test, type Page } from '@playwright/test';

/**
 * The front door, in a browser.
 *
 * `/` is the shop window since ADR-041 — a person arriving has not asked to
 * sign in, they have arrived — and what this spec holds true is the shape of
 * that: prices visible with no session, signing in present but secondary, and
 * choosing an offer leading to an account rather than to a login form.
 *
 * The whole purchase is not driven here. Its last step is a payment provider's
 * hosted page, which no E2E suite should be pretending to be; what is asserted
 * is that the storefront hands off to the ordinary authenticated checkout,
 * which the integration suite then proves end to end against a real database.
 */
const OFFER = {
  id: '44444444-4444-4444-8444-444444444444',
  code: 'pro-monthly',
  name: 'Pro, monthly',
  plan: { id: '55555555-5555-4555-8555-555555555555', code: 'pro', name: 'Pro', rank: 10 },
  version: {
    id: '66666666-6666-4666-8666-666666666666',
    version: 1,
    billing_period: 'MONTHLY',
    price: { minor_units: 2900, currency: 'EUR' },
    valid_from: '2026-01-01T00:00:00Z',
    valid_until: null,
    grants: [],
    terms: null,
  },
};

const NO_SESSION = {
  status: 401,
  json: {
    error: { code: 'UNAUTHENTICATED', message: 'Authentication is required.', details: {}, request_id: 'r' },
  },
};

/**
 * A deployment with one product and one advertised offer.
 *
 * The catch-all is registered first so the specific routes win — the ordering
 * trap `accessibility.spec.ts` recorded in U9, where a catch-all added last
 * silently answered everything.
 */
async function stubStorefront(page: Page, window: object = { product: { code: 'atlas', name: 'Atlas' }, offers: [OFFER] }): Promise<string[]> {
  const asked: string[] = [];

  await page.route(/\/api\/v1\//, (route) => {
    asked.push(new URL(route.request().url()).pathname);

    return route.fulfill({ status: 404, json: { error: { code: 'NOT_FOUND' } } });
  });
  await page.route('**/api/v1/auth/refresh', (route) => {
    asked.push('/api/v1/auth/refresh');

    return route.fulfill(NO_SESSION);
  });
  await page.route(/\/api\/v1\/public\/offers(\?|$)/, (route) => {
    asked.push(new URL(route.request().url()).pathname);

    return route.fulfill({ json: window });
  });
  // The shop windows there are: one, so the page chooses it itself and
  // `?product=` in the address only agrees with it.
  await page.route(/\/api\/v1\/public\/products$/, (route) => {
    asked.push('/api/v1/public/products');

    return route.fulfill({ json: { products: [{ code: 'atlas', name: 'Atlas' }] } });
  });

  return asked;
}

const SESSION = {
  id: '88888888-8888-4888-8888-888888888888',
  order_id: '88888888-8888-4888-8888-888888888888',
  status: 'AWAITING_PAYMENT',
  invoice_id: 'inv-1',
  subscription_id: null,
  payment_id: 'pay-1',
  payment_status: 'PENDING',
  net: { minor_units: 2900, currency: 'EUR' },
  vat: { minor_units: 0, currency: 'EUR' },
  gross: { minor_units: 2900, currency: 'EUR' },
};

async function stubSignUp(page: Page): Promise<void> {
  await page.route('**/api/v1/auth/sign-up', (route) =>
    route.fulfill({
      status: 201,
      json: { access_token: 'access-token', token_type: 'Bearer', expires_in: 3600, tenant_id: '77777777-7777-4777-8777-777777777777' },
    }),
  );
}

/**
 * A Stripe.js that is honestly not one, served where the real one would load
 * from. It satisfies what @stripe/react-stripe-js checks for, mounts a box
 * where the Payment Element would be, and `confirmPayment` resolves — the
 * browser's word, which the page must not read as a status.
 */
async function stubStripeJs(page: Page): Promise<void> {
  await page.route(/^https:\/\/js\.stripe\.com\/v3\/?(\?.*)?$/, (route) =>
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

test.describe('the shop window', () => {
  test('shows prices to somebody who has never signed in', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');

    await expect(page.getByText('Pro, monthly')).toBeVisible();
    await expect(page.getByText('€29.00')).toBeVisible();
    // No form. Arriving is not asking to sign in.
    await expect(page.getByLabel('Password')).toBeHidden();
  });

  test('asks the public list of windows, never the membership list', async ({ page }) => {
    const asked = await stubStorefront(page);

    await page.goto('/?product=atlas');
    await expect(page.getByText('Pro, monthly')).toBeVisible();

    // What the platform *runs* is a membership's answer and stays behind the
    // gate; what it *advertises* is the public list (ADR-047).
    expect(asked.filter((path) => path === '/api/v1/products')).toHaveLength(0);
    expect(asked).toContain('/api/v1/public/products');
    expect(asked).toContain('/api/v1/public/offers');
  });

  test('keeps signing in available and secondary', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');

    // Below the offers, not instead of them — and it does reach the form.
    await page.getByTestId('sign-in-link').click();
    await expect(page.getByLabel('Password')).toBeVisible();
  });

  test('says nothing about why a window is empty', async ({ page }) => {
    await stubStorefront(page, { product: null, offers: [] });

    await page.goto('/?product=nothing-here');

    // One message, because the API gives one answer for "no such product", "not
    // active" and "advertises nothing".
    await expect(page.getByText('Nothing on sale here')).toBeVisible();
  });
});

test.describe('choosing an offer', () => {
  test('asks for an account rather than for a password', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    await expect(page.getByRole('heading', { name: 'Create your account' })).toBeVisible();
    // The price they chose stays in front of them while they type.
    await expect(page.getByTestId('chosen-offer')).toContainText('€29.00');
  });

  test('does not make a consumer invent a company', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    // Optional in the label, not merely optional in the validator: somebody
    // buying for themselves has to be able to see that they may skip it.
    await expect(page.getByLabel('Company (optional)')).toBeVisible();
    await expect(page.getByText(/buying for yourself/i)).toBeVisible();
  });

  test('lets them go back to the offers', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();
    await page.getByTestId('choose-another').click();

    await expect(page.getByText('Pro, monthly')).toBeVisible();
  });

  test('creates the account and opens the checkout for what was chosen', async ({ page }) => {
    await stubStorefront(page);

    let boughtOfferId: unknown = null;

    await page.route('**/api/v1/auth/sign-up', (route) =>
      route.fulfill({
        status: 201,
        json: {
          access_token: 'access-token',
          token_type: 'Bearer',
          expires_in: 3600,
          tenant_id: '77777777-7777-4777-8777-777777777777',
        },
      }),
    );

    await page.route('**/api/v1/checkout/sessions', (route) => {
      boughtOfferId = (route.request().postDataJSON() as { offer_id?: unknown }).offer_id;

      return route.fulfill({
        status: 201,
        json: {
          session: {
            ...SESSION,
            client_secret: 'stub_secret_1',
            payment_provider: { name: 'stub', publishable_key: null, sandbox: true },
          },
        },
      });
    });

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('a-long-enough-password');
    await page.getByLabel('Company (optional)').fill('Acme Ltd');
    await page.getByRole('button', { name: 'Create account and continue' }).click();

    // The pay step, where the secret was born (ADR-048). The stub has no card
    // form, so it says so and the order is the way on.
    await expect(page.getByTestId('payment-panel')).toBeVisible();
    // The offer they chose, bought on the session the sign-up issued — not a
    // second anonymous purchase flow, and not a second choice to make.
    expect(boughtOfferId).toBe(OFFER.id);
    await expect(page.getByTestId('payment-no-panel')).toBeVisible();
    await expect(page.getByTestId('sandbox-band')).toBeVisible();
    await page.getByTestId('continue-to-order').click();
    await expect(page).toHaveURL(/\/checkout\/88888888-8888-4888-8888-888888888888/);
  });

  test('pays through the provider\'s form when it has one, then lands on the order', async ({ page }) => {
    await stubStorefront(page);
    await stubSignUp(page);
    await stubStripeJs(page);

    await page.route('**/api/v1/checkout/sessions', (route) =>
      route.fulfill({
        status: 201,
        json: {
          session: {
            ...SESSION,
            client_secret: 'pi_1_secret_x',
            payment_provider: { name: 'stripe', publishable_key: 'pk_test_e2e', sandbox: true },
          },
        },
      }),
    );

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();
    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('a-long-enough-password');
    await page.getByRole('button', { name: 'Create account and continue' }).click();

    // Stripe's form, in the page, with the amount the server named — and the
    // sandbox said out loud.
    await expect(page.getByTestId('stripe-form')).toBeVisible();
    await expect(page.getByTestId('sandbox-band')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Pay/ })).toContainText('29');

    // Enabled once Stripe.js has loaded — the fake one, from the route above.
    await expect(page.getByRole('button', { name: /^Pay/ })).toBeEnabled();
    await page.getByRole('button', { name: /^Pay/ }).click();

    // Whatever the form said, the status page is where the answer is read.
    await expect(page).toHaveURL(/\/checkout\/88888888-8888-4888-8888-888888888888/);
    // The secret went to the provider and nowhere the page keeps.
    expect(await page.evaluate(() => JSON.stringify(window.localStorage))).not.toContain('pi_1_secret');
  });

  test('says plainly when the address already has an account', async ({ page }) => {
    await stubStorefront(page);

    await page.route('**/api/v1/auth/sign-up', (route) =>
      route.fulfill({
        status: 409,
        json: {
          error: { code: 'EMAIL_TAKEN', message: 'Taken.', details: {}, request_id: 'r' },
        },
      }),
    );

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('a-long-enough-password');
    await page.getByRole('button', { name: 'Create account and continue' }).click();

    // The one place this platform tells anybody an account exists. Somebody who
    // cannot be told cannot finish the purchase they came for.
    await expect(page.getByRole('alert')).toContainText('already has an account');
  });
});

test.describe('the storefront is a screen like any other', () => {
  test('passes an accessibility scan', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).waitFor();

    // The first page anybody sees, and the one nobody signs in to reach — so a
    // failure here is a failure for every visitor rather than for every user.
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });

  test('the sign-up form passes one too', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();
    await page.getByLabel('Email').waitFor();

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });
});
