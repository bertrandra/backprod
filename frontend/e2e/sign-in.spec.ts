import AxeBuilder from '@axe-core/playwright';
// The raw `test`, deliberately: `support/app` hands every other spec a session,
// and this is the spec about not having one yet.
import { expect, test, type Page, type Route } from '@playwright/test';

/**
 * How a person gets in, in a browser.
 *
 * Before U11 the answer was "they do not": `signIn` was exported and called by
 * nothing, so a deployed build rendered all thirty-four areas and left every
 * visitor anonymous. U11 built the screen against Supabase; **U12 moved issuance
 * into PHP**, so what these tests drive is three routes in this application's own
 * contract and there is no external service in the picture at all.
 *
 * The refresh token never appears here, and that is the assertion behind several
 * of these: it is an `HttpOnly` cookie, so the page cannot read it, and neither
 * can a test. What a test can check is that the browser sends it back — which is
 * what "coming back later" does.
 */

const ME = {
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
  await page.route(/\/api\/v1\/me$/, (route) => route.fulfill({ json: ME }));
  await page.route(/\/api\/v1\/me\/permissions$/, (route) =>
    route.fulfill({ json: { permissions: ME.permissions } }),
  );
  await page.route(/\/api\/v1\/products$/, (route) =>
    route.fulfill({ json: { products: [{ id: ME.product_id, code: 'atlas', name: 'Atlas' }] } }),
  );
}

/**
 * The three auth routes, stubbed one at a time.
 *
 * **A single `**\/auth/**` pattern was the first attempt and it broke ten tests.**
 * The application asks `/auth/refresh` on every load, so one stub answering the
 * whole prefix with a session signed the page in before it could render the form —
 * and every test about *not* having a session failed. The two routes mean opposite
 * things here and have to be answerable separately: no session is `refresh → 401`,
 * a returning visitor is `refresh → 200`.
 *
 * Three exact patterns rather than one prefix, so registration order does not
 * matter between them.
 */
async function stubAuth(
  page: Page,
  answers: {
    refresh?: { status: number; json?: object };
    token?: { status: number; json?: object };
    signOut?: { status: number; json?: object };
  },
): Promise<string[]> {
  const asked: string[] = [];

  const record = (answer: { status: number; json?: object }) => (route: Route) => {
    asked.push(route.request().url());

    return route.fulfill(answer);
  };

  // Registered after `stubApi`, so each wins over that catch-all — Playwright uses
  // the most recently registered route (U9).
  await page.route('**/api/v1/auth/refresh', record(answers.refresh ?? NO_SESSION));
  await page.route('**/api/v1/auth/token', record(answers.token ?? NO_SESSION));
  await page.route('**/api/v1/auth/sign-out', record(answers.signOut ?? { status: 204 }));

  return asked;
}

/** What the API answers when there is no session, or the credential is wrong. */
const NO_SESSION = {
  status: 401,
  json: {
    error: { code: 'UNAUTHENTICATED', message: 'Authentication is required.', details: {}, request_id: 'r' },
  },
};

const SESSION = { access_token: 'access-token', token_type: 'Bearer', expires_in: 3600 };

/**
 * Opens the sign-in form the way a person does since ADR-041.
 *
 * The landing page is the shop window now, and signing in is a line of text
 * below the offers — so "go to the form" is two steps, and making the tests
 * take both is what keeps them testing the route that exists rather than the
 * one that used to.
 *
 * It matches the storefront's link by test id rather than by name: the form's
 * own submit button is also called "Sign in", and a locator that matched both
 * would pass for the wrong reason the day the click stopped working.
 */
async function openSignIn(page: Page): Promise<void> {
  await page.goto('/');
  await page.getByTestId('sign-in-link').click();
  await page.getByLabel('Password').waitFor();
}

test.describe('arriving with no session', () => {
  test('is asked to sign in, and nothing behind the gate is fetched', async ({ page }) => {
    const apiCalls: string[] = [];

    await page.route(/\/api\//, (route) => {
      apiCalls.push(new URL(route.request().url()).pathname);

      return route.fulfill({ status: 401, json: { error: { code: 'UNAUTHENTICATED' } } });
    });

    // A path *behind* the gate. Since ADR-041 the landing page is the
    // storefront — arriving is not asking to sign in — so this asks for
    // something that genuinely requires a session.
    await page.goto('/profile?product=atlas');

    await expect(page.getByLabel('Password')).toBeVisible();

    // Exactly one call, and it is the gate asking whether there is a session to
    // resume. U11's version of this test asserted *no* calls, which was true when
    // the answer came from `localStorage`; since U12 the question is asked of the
    // server, so "nothing behind the gate ran" means nothing *else* ran.
    //
    // Thirty screens each explaining a 401 is still the alternative being ruled
    // out, and that is what this now checks.
    expect(apiCalls).toEqual(['/api/v1/auth/refresh']);
  });

  test('signing in from the landing page, with no product in the address, opens the application rather than a blank screen', async ({ page }) => {
    await stubApi(page);
    await stubAuth(page, { token: { status: 200, json: SESSION } });
    await page.route(/\/api\/v1\/public\/products$/, (route) =>
      route.fulfill({ json: { products: [{ code: 'atlas', name: 'Atlas' }] } }),
    );
    await page.route(/\/api\/v1\/public\/offers/, (route) => route.fulfill({ json: { offers: [] } }));

    // The way the operator found it: sign out lands on "/", and "/" carries
    // no `?product=`. Before 2026-09-17 nothing chose a product, so nothing
    // could ask `/me`, so there was no menu and nothing on top.
    await page.goto('/');
    await page.getByTestId('sign-in-link').click();
    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('correct horse');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // The one product this person has is chosen for them and the shell fills.
    await expect(page.getByTestId('active-product')).toHaveAttribute('data-product', 'atlas');
    // A menu: the sidebar's entry on a desktop, the bottom bar's on a phone.
    await expect(page.locator('[data-nav="profile"]:visible, [data-nav-bottom="profile"]:visible')).toHaveCount(1);
    await expect(page.getByTestId('account-menu')).toHaveText('A');
    // And the first screen in that menu is where they land (2026-09-18):
    // the same place a deep link's form leads, not a page the menu leads
    // with somewhere else.
    await expect(page).toHaveURL(/\/profile/);
  });

  test('keeps the deep link it was asked for, and lands there after signing in', async ({ page }) => {
    await stubApi(page);
    await stubAuth(page, { token: { status: 200, json: SESSION } });

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

  test('exchanges the password at this platform’s own token endpoint', async ({ page }) => {
    await stubApi(page);
    const asked = await stubAuth(page, { token: { status: 200, json: SESSION } });

    await openSignIn(page);
    await page.getByLabel('Email').fill('ada@acme.test');
    await page.getByLabel('Password').fill('correct horse');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('button', { name: 'Sign in' })).toBeHidden();

    // Filtered, because `asked` also holds the refresh the gate makes on load —
    // which is the right thing for it to hold, and not what this test is about.
    // Same origin as everything else: no third-party request is made at any point,
    // which is the whole reason U12 exists.
    expect(asked.filter((url) => url.endsWith('/api/v1/auth/token'))).toHaveLength(1);
  });

  test('says one sentence when the credential is refused, and clears the password', async ({ page }) => {
    await stubApi(page);
    await stubAuth(page, {});

    await openSignIn(page);
    await page.getByLabel('Email').fill('ada@acme.test');
    // Long enough to pass the form's own minimum, so the server is what refuses
    // it. Typing 'wrong' — which is what this test did first — never leaves the
    // browser, and the assertion then reads the field's own message instead.
    await page.getByLabel('Password').fill('wrong but long enough');
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
    await stubAuth(page, { token: { status: 200, json: SESSION } });
    await openSignIn(page);
    await page.getByLabel('Password').waitFor();

    // The one screen the U9 suite could not reach, because that suite is signed
    // in by the time it scans anything.
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });
});

test.describe('coming back later', () => {
  test('a browser holding the cookie is signed straight in', async ({ page }) => {
    await stubApi(page);
    const asked = await stubAuth(page, { refresh: { status: 200, json: SESSION } });

    // Nothing is seeded into storage, because there is nothing in storage to seed.
    // The session resumes because the refresh endpoint answers — which is what a
    // real browser holding the `HttpOnly` cookie causes.
    await page.goto('/profile?product=atlas');

    await expect(page.getByRole('heading', { name: 'Your profile' })).toBeVisible();
    expect(asked[0]).toContain('/api/v1/auth/refresh');
  });

  test('a session the server no longer honours lands on the form', async ({ page }) => {
    await stubApi(page);
    await stubAuth(page, {});

    await page.goto('/profile?product=atlas');

    await expect(page.getByLabel('Password')).toBeVisible();
    // And there is nothing in storage to clear, because U12 put nothing there. The
    // credential was a cookie, and the server is what decides it is spent.
    expect(await page.evaluate(() => Object.keys(window.localStorage))).not.toContain(
      'backprod.refresh',
    );
  });
});

test.describe('signing out', () => {
  test('ends the session and asks the server to revoke it', async ({ page }) => {
    await stubApi(page);
    const asked = await stubAuth(page, { refresh: { status: 200, json: SESSION } });

    await page.goto('/profile?product=atlas');
    await page.getByRole('heading', { name: 'Your profile' }).waitFor();

    await page.getByRole('button', { name: 'Sign out' }).click();

    // Home, not a sign-in form for the profile just left: the storefront, at
    // the landing address, with its own way back in.
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByTestId('sign-in-link')).toBeVisible();
    // "Signed out" should mean the credential is dead rather than mislaid: the
    // server revokes the refresh token, and clearing the cookie alone would leave
    // it valid for anybody holding a copy.
    expect(asked.some((url) => url.endsWith('/api/v1/auth/sign-out'))).toBe(true);
  });

  test('is one click away on every screen, from the account circle', async ({ page }) => {
    await stubApi(page);
    const asked = await stubAuth(page, { refresh: { status: 200, json: SESSION } });

    await page.goto('/?product=atlas');
    const circle = page.getByTestId('account-menu');
    await circle.waitFor();

    // The initial, and the name on hover — a screen reader hears the same.
    await expect(circle).toHaveText('A');
    await expect(circle).toHaveAttribute('title', 'Ada');

    await circle.click();
    await expect(page.getByRole('menu', { name: 'Account' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();

    await expect(page.getByTestId('sign-in-link')).toBeVisible();
    expect(asked.some((url) => url.endsWith('/api/v1/auth/sign-out'))).toBe(true);
  });
});
