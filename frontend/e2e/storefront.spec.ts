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
 * Since 2026-09-17 the door is a request to join the organisation at this
 * root, not a purchase: a sign-up makes a USER membership, live or waiting,
 * and a USER cannot check out (docs/tenant-roots.md §2.4). So the form ends
 * at the root, where the shell says "in" or "waiting"; buying is the
 * administrator's, on the catalogue, and `commerce.spec.ts` drives it.
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
  await page.route(/\/api\/v1\/public\/products(\?|$)/, (route) => {
    asked.push('/api/v1/public/products');

    return route.fulfill({ json: { products: [{ code: 'atlas', name: 'Atlas' }] } });
  });
  // Whose window: the bare host's default organisation (2026-09-17).
  await page.route(/\/api\/v1\/public\/tenant(\?|$)/, (route) => {
    asked.push('/api/v1/public/tenant');

    return route.fulfill({ json: { tenant: { slug: 'acme', name: 'Acme Ltd', is_default: true } } });
  });

  return asked;
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
  test('asks to join the organisation rather than for a password', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    await expect(page.getByRole('heading', { name: 'Join Acme Ltd' })).toBeVisible();
    // The price they chose stays in front of them — and who buys is said.
    await expect(page.getByTestId('chosen-offer')).toContainText('€29.00');
    await expect(page.getByText(/an administrator buys/i)).toBeVisible();
  });

  test('asks for nothing an organisation would be made of', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    // No company, no country: the organisation at this root has both.
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel(/Company/)).toHaveCount(0);
    await expect(page.getByLabel(/Country/)).toHaveCount(0);
  });

  test('lets them go back to the offers', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();
    await page.getByTestId('choose-another').click();

    await expect(page.getByText('Pro, monthly')).toBeVisible();
  });

  test('has a door from the footer too, with nothing in hand', async ({ page }) => {
    await stubStorefront(page);

    await page.goto('/?product=atlas');
    await page.getByTestId('sign-up-link').click();

    await expect(page.getByRole('heading', { name: 'Join Acme Ltd' })).toBeVisible();
    await expect(page.getByTestId('chosen-offer')).toHaveCount(0);
  });

  test('creates the account, names the organisation, and goes to the root', async ({ page }) => {
    await stubStorefront(page);

    let sent: unknown = null;

    await page.route('**/api/v1/auth/sign-up', (route) => {
      sent = route.request().postDataJSON();

      return route.fulfill({
        status: 201,
        json: {
          access_token: 'access-token',
          token_type: 'Bearer',
          expires_in: 3600,
          tenant_id: '77777777-7777-4777-8777-777777777777',
          tenant: 'acme',
          membership: 'PENDING',
        },
      });
    });
    // Signed in at the root afterwards: nothing to open yet, and the shell
    // says where they wait.
    await page.route('**/api/v1/auth/refresh', (route) =>
      route.fulfill({ json: { access_token: 'access-token', token_type: 'Bearer', expires_in: 3600 } }),
    );
    await page.route(/\/api\/v1\/products$/, (route) =>
      route.fulfill({ json: { products: [], default: null, pending_memberships: [{ tenant: 'acme', name: 'Acme Ltd' }] } }),
    );

    await page.goto('/?product=atlas');
    await page.getByRole('button', { name: 'Choose' }).click();

    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('a-long-enough-password');
    await page.getByRole('button', { name: 'Create account and join' }).click();

    await expect(page.getByTestId('waiting-for-approval')).toBeVisible();
    await expect(page).toHaveURL(/\/$/);
    // The organisation from the root, never typed; the product for the default.
    expect(sent).toMatchObject({ email: 'ada@acme.test', tenant: 'acme', product: 'atlas' });
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
    await page.getByRole('button', { name: 'Create account and join' }).click();

    // The one place this platform tells anybody an account exists. Somebody who
    // cannot be told cannot finish what they came for.
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
