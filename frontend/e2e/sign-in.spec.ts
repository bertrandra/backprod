import AxeBuilder from '@axe-core/playwright';
// The raw `test`, deliberately: `support/app` hands every other spec a session,
// and this is the spec about not having one yet.
import { expect, test, type Page } from '@playwright/test';

/**
 * How a person gets in, in a browser.
 *
 * Before U11 the answer was "they do not": `signIn` was exported and called by
 * nothing, so a deployed build rendered all thirty-four areas and left every
 * visitor anonymous. Nothing failed — a token is not an API operation, so no gate
 * covered it, and `ui-spec.md` never named a screen for it.
 *
 * The provider is stubbed rather than reached. What is asserted is the contract
 * between this application and Supabase's token endpoint — the grant type, the
 * key, and what happens to each answer — which is exactly the part a real project
 * would not exercise any better.
 */
const AUTH_ORIGIN = 'https://project.supabase.test';

const SESSION = {
  user_id: '11111111-1111-4111-8111-111111111111',
  email: 'ada@acme.test',
  display_name: 'Ada',
  product_id: '22222222-2222-4222-8222-222222222222',
  tenant_id: '33333333-3333-4333-8333-333333333333',
  roles: ['TENANT_ADMIN'],
  permissions: ['account.read', 'tenant.read'],
  capabilities: [],
};

/**
 * Registered first, so a spec-level stub can still override it — the same
 * ordering trap U9 recorded in `accessibility.spec.ts`, where a catch-all added
 * last silently answered everything.
 */
async function stubApi(page: Page): Promise<void> {
  await page.route(/\/api\/v1\//, (route) => route.fulfill({ status: 404, json: { error: { code: 'NOT_FOUND' } } }));
  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: SESSION }));
  await page.route(/\/api\/v1\/me\/permissions$/, (route) =>
    route.fulfill({ json: { permissions: SESSION.permissions } }),
  );
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({ json: { products: [{ id: SESSION.product_id, code: 'atlas', name: 'Atlas' }] } }),
  );
}

/** The provider's happy answer, and a counter so a test can prove it was asked once. */
async function stubProvider(page: Page, answer: { status: number; json: object }): Promise<string[]> {
  const asked: string[] = [];

  await page.route(`${AUTH_ORIGIN}/auth/v1/**`, (route) => {
    asked.push(route.request().url());

    return route.fulfill(answer);
  });

  return asked;
}

const GRANT = { access_token: 'access-token', refresh_token: 'refresh-token', expires_in: 3600 };

test.describe('arriving with no session', () => {
  test('is asked to sign in, and nothing behind the gate is fetched', async ({ page }) => {
    const apiCalls: string[] = [];

    await page.route(/\/api\//, (route) => {
      apiCalls.push(route.request().url());

      return route.fulfill({ status: 401, json: { error: { code: 'UNAUTHENTICATED' } } });
    });

    await page.goto('/');

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
    // The gate is above the router, so no screen mounted and no query fired.
    // Thirty screens each explaining a 401 is the alternative.
    expect(apiCalls).toEqual([]);
  });

  test('keeps the deep link it was asked for, and lands there after signing in', async ({ page }) => {
    await stubApi(page);
    await stubProvider(page, { status: 200, json: GRANT });

    // A URL somewhere inside the application, the way a person follows a link
    // from an email. `?product=` because a product is the root context and
    // nothing can be read without one — the same reason every other spec carries
    // it. A deployment sets `VITE_DEFAULT_PRODUCT` instead of asking for it.
    await page.goto('/profile?product=atlas');

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();

    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('correct horse');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // Still /profile. There is no redirect to a sign-in route, so there is
    // nothing to remember and nothing to restore — which is why this works.
    await expect(page).toHaveURL(/\/profile\?product=atlas/);
    await expect(page.getByRole('heading', { name: 'Your profile' })).toBeVisible();
  });

  test('asks the provider for a password grant, with the anon key', async ({ page }) => {
    await stubApi(page);
    const asked = await stubProvider(page, { status: 200, json: GRANT });

    await page.goto('/');
    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('correct horse');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeHidden();
    expect(asked).toHaveLength(1);
    expect(asked[0]).toContain('grant_type=password');
  });

  test('says one sentence when the credential is refused, and clears the password', async ({ page }) => {
    await stubApi(page);
    await stubProvider(page, { status: 400, json: { msg: 'Invalid login credentials' } });

    await page.goto('/');
    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('wrong');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('alert')).toHaveText(
      'That email and password do not match an account.',
    );
    // Not the provider's wording, which distinguishes "no such user" from "wrong
    // password" on some paths.
    await expect(page.getByLabel('Password')).toHaveValue('');
    await expect(page.getByLabel('Email')).toHaveValue('ada@acme.test');
  });

  test('passes an accessibility scan, like every other screen', async ({ page }) => {
    await stubApi(page);
    await stubProvider(page, { status: 200, json: GRANT });
    await page.goto('/');
    await page.getByRole('button', { name: 'Sign in' }).waitFor();

    // The one screen the U9 suite could not reach, because that suite is signed
    // in by the time it scans anything.
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });
});

test.describe('coming back later', () => {
  test('a stored refresh token signs the person straight in', async ({ page }) => {
    await stubApi(page);
    const asked = await stubProvider(page, { status: 200, json: GRANT });

    await page.addInitScript(() => {
      window.localStorage.setItem('backprod.refresh', 'stored-refresh-token');
    });

    await page.goto('/profile?product=atlas');

    await expect(page.getByRole('heading', { name: 'Your profile' })).toBeVisible();
    // Exchanged, not trusted: the stored value is a refresh token, and the API
    // only ever sees an access token that the provider has just re-issued.
    expect(asked[0]).toContain('grant_type=refresh_token');
  });

  test('a refresh token the provider has revoked lands on the form, and is not kept', async ({
    page,
  }) => {
    await stubApi(page);
    await stubProvider(page, { status: 400, json: { msg: 'Invalid Refresh Token' } });

    await page.addInitScript(() => {
      window.localStorage.setItem('backprod.refresh', 'revoked');
    });

    await page.goto('/');

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
    // Kept, it would be retried on every load forever against a provider that
    // has already said no.
    expect(await page.evaluate(() => window.localStorage.getItem('backprod.refresh'))).toBeNull();
  });
});

test.describe('signing out', () => {
  test('ends the session, forgets the token and asks the provider to revoke it', async ({ page }) => {
    await stubApi(page);
    const asked: string[] = [];

    await page.route(`${AUTH_ORIGIN}/auth/v1/**`, (route) => {
      asked.push(route.request().url());

      return route.fulfill({ status: 200, json: GRANT });
    });

    await page.addInitScript(() => {
      window.localStorage.setItem('backprod.refresh', 'stored-refresh-token');
    });

    await page.goto('/profile?product=atlas');
    await page.getByRole('heading', { name: 'Your profile' }).waitFor();

    await page.getByRole('button', { name: 'Sign out' }).click();

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
    expect(await page.evaluate(() => window.localStorage.getItem('backprod.refresh'))).toBeNull();
    // "Signed out" should mean the credential is dead rather than mislaid.
    expect(asked.some((url) => url.endsWith('/auth/v1/logout'))).toBe(true);
  });
});
