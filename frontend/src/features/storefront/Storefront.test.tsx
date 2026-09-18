import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useSessionStore } from '@/state/session';
import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { Storefront } from './Storefront';
import { StorefrontScreen } from './StorefrontScreen';

/**
 * The page a stranger lands on, and the purchase that starts from it.
 *
 * Two things are being asserted and they pull in opposite directions: that
 * somebody with no account can see prices and buy, and that seeing prices
 * reveals nothing else. The second is easy to lose the moment the first is
 * made convenient, so both are here.
 */
const OFFER = {
  id: 'offer-1',
  code: 'pro-monthly',
  name: 'Pro, monthly',
  plan: { id: 'plan-1', code: 'pro', name: 'Pro', rank: 10 },
  version: {
    id: 'v-1',
    version: 1,
    billing_period: 'MONTHLY',
    price: { minor_units: 2900, currency: 'EUR' },
    valid_from: '2026-01-01T00:00:00Z',
    valid_until: null,
    grants: [],
    terms: null,
  },
};

const WINDOW = { product: { code: 'atlas', name: 'Atlas' }, offers: [OFFER] };

const ATLAS = { code: 'atlas', name: 'Atlas' };
const BOREAS = { code: 'boreas', name: 'Boreas' };
const ACME = { slug: 'acme', name: 'Acme Ltd', is_default: true, join_policy: 'OPEN' };
const GUARDED = { ...ACME, join_policy: 'APPROVAL' };

const MONEY = { minor_units: 2900, currency: 'EUR' };
const SESSION = {
  id: 'order-1',
  status: 'AWAITING_PAYMENT',
  payment_id: 'pay-1',
  net: MONEY,
  vat: { minor_units: 0, currency: 'EUR' },
  gross: MONEY,
};
// A provider with no card form in the page: the stub. What the pay step
// does *without* Stripe is what these tests are about; the form itself is
// PaymentElementPanel.test.tsx's.
const STUB_PROVIDER = { name: 'stub', publishable_key: null, sandbox: true };

const CREATED = {
  access_token: 'access',
  token_type: 'Bearer',
  expires_in: 3600,
  tenant_id: 'tenant-1',
  tenant: 'acme',
  membership: 'ACTIVE',
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/public/tenant': { data: { tenant: ACME } },
    'GET /api/v1/public/products': { data: { products: [ATLAS] } },
    'GET /api/v1/public/offers': { data: WINDOW },
    'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
    'POST /api/v1/checkout/sessions': { status: 201, data: { session: SESSION } },
    ...extra,
  });
}

/** Fills the form the way a person does, and submits it. */
function signUpAs(fields: { email: string; password: string; name?: string }): void {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: fields.email } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: fields.password } });

  if (fields.name !== undefined) {
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: fields.name } });
  }

  fireEvent.click(screen.getByRole('button', { name: /Create account and (join|continue)/i }));
}

/** Points the page at the offers and opens the door with the first one. */
async function chooseTheOffer(): Promise<void> {
  await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
  fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
  await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());
}

beforeEach(() => {
  window.localStorage.clear();
  // The root too: a bare-host render teaches the store the default slug,
  // which would otherwise leak into the next test as an address.
  useSessionStore.setState({
    token: null,
    productCode: 'atlas',
    status: 'anonymous',
    expiresAt: null,
    root: '',
    tenantSlug: null,
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('the shop window', () => {
  it('shows prices to somebody with no account', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());

    expect(screen.getByText('Pro, monthly')).toBeTruthy();
    // The integer the API sent, not the rendered string: `Intl` formats it
    // for the runtime's locale, and a French machine says "29,00 €".
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });

  it('asks the public list of windows, never the membership list', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/products': { data: { products: [ATLAS] } },
      'GET /api/v1/public/offers': { data: WINDOW },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(requests.some((r) => r.path === '/api/v1/public/offers')).toBe(true));

    // The product is a filter the caller names, and what it may be chosen from
    // is the list of shop windows — products with something advertised. What
    // the platform *runs* is a membership's answer, and a stranger has none.
    expect(requests.some((r) => r.path === '/api/v1/public/products')).toBe(true);
    expect(requests.find((r) => r.path === '/api/v1/public/offers')?.query).toEqual({ product: 'atlas' });
    expect(requests.some((r) => r.path === '/api/v1/products')).toBe(false);
  });

  it('chooses the only window itself, and offers no control', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor(), { product: null });

    // A dropdown with one option is a question with one answer.
    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    expect(useSessionStore.getState().productCode).toBe('atlas');
    expect(screen.queryByTestId('storefront-product')).toBeNull();
  });

  it('lets them choose between several windows, and re-asks for the offers', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/products': { data: { products: [ATLAS, BOREAS] } },
      'GET /api/v1/public/offers': { data: WINDOW },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client, { product: null });

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('storefront-product'));

    // Nothing chosen yet: the question is the dropdown, and no window was asked for.
    expect(screen.getByText('Choose a product')).toBeTruthy();
    expect(requests.some((r) => r.path === '/api/v1/public/offers')).toBe(false);
    expect([...select.options].map((option) => option.value)).toEqual(['', 'atlas', 'boreas']);

    fireEvent.change(select, { target: { value: 'boreas' } });

    expect(useSessionStore.getState().productCode).toBe('boreas');
    await waitFor(() =>
      expect(requests.find((r) => r.path === '/api/v1/public/offers')?.query).toEqual({ product: 'boreas' }),
    );
  });

  it('keeps a link to one product working when there are several', async () => {
    // ?product= seeded the store before this rendered, as `useProductContext`
    // does; several windows must not un-choose what the address named.
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({ 'GET /api/v1/public/products': { data: { products: [ATLAS, BOREAS] } } }),
      { product: 'boreas' },
    );

    const select = await waitFor(() => screen.getByTestId<HTMLSelectElement>('storefront-product'));

    expect(select.value).toBe('boreas');
    expect(useSessionStore.getState().productCode).toBe('boreas');
  });

  it('says so plainly when nothing is on sale anywhere, and still offers signing in', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({ 'GET /api/v1/public/products': { data: { products: [] } } }),
      { product: null },
    );

    // Not an error to apologise for, and not somebody's fault: nothing has been
    // advertised on this deployment yet. The person with an account can still
    // get in.
    await waitFor(() => expect(screen.getByText(/Nothing on sale yet/i)).toBeTruthy());
    expect(screen.queryByTestId('storefront-product')).toBeNull();
    expect(screen.getByTestId('sign-in-link')).toBeTruthy();
  });

  it('shows the windows before one is chosen, as the screen', () => {
    // The screen alone, with the list in hand and nothing chosen: the dropdown
    // is the question and nothing pretends to be an empty shop.
    renderWith(
      <StorefrontScreen
        productCode={null}
        products={[ATLAS, BOREAS]}
        onChooseProduct={() => undefined}
        onChoose={() => undefined}
        onSignIn={() => undefined}
      />,
      clientFor(),
    );

    expect(screen.getByTestId('storefront-product')).toBeTruthy();
    expect(screen.getByText('Choose a product')).toBeTruthy();
    expect(screen.queryByText(/Nothing on sale/i)).toBeNull();
  });

  it('gives one answer for an empty window, whatever emptied it', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({ 'GET /api/v1/public/offers': { data: { product: null, offers: [] } } }),
    );

    // The API deliberately does not say whether the product exists, is off, or
    // advertises nothing. Telling them apart here would leak what it withholds.
    await waitFor(() => expect(screen.getByText(/Nothing on sale here/i)).toBeTruthy());
  });

  it('keeps signing in secondary, not the front door', async () => {
    const onSignIn = vi.fn();

    renderWith(<Storefront onSignIn={onSignIn} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());

    // Present and reachable, below the offers rather than instead of them: an
    // account is what buying produces, not what it requires.
    fireEvent.click(screen.getByTestId('sign-in-link'));
    expect(onSignIn).toHaveBeenCalled();
  });
});

describe('choosing an offer', () => {
  it('opens the door with the offer in hand, to join and pay', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await chooseTheOffer();

    // The organisation at this root is the one being asked, by name; and
    // under OPEN the form promises the purchase that follows.
    expect(screen.getByText(/Join Acme Ltd/i)).toBeTruthy();
    expect(screen.getByText(/pay straight after/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Create account and continue' })).toBeTruthy();
  });

  it('says an administrator accepts first where the policy is approval', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({ 'GET /api/v1/public/tenant': { data: { tenant: GUARDED } } }),
    );

    await chooseTheOffer();

    expect(screen.getByText(/accepts new members/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Create account and join' })).toBeTruthy();
  });

  it('keeps the chosen offer and its price in front of them while they type', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await chooseTheOffer();

    const chosen = screen.getByTestId('chosen-offer');
    expect(chosen.textContent).toContain('Pro, monthly');
    expect(chosen.querySelector('[data-minor-units="2900"]')).not.toBeNull();
  });

  it('lets them go back and choose differently', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await chooseTheOffer();
    fireEvent.click(screen.getByTestId('choose-another'));

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
  });

  it('has a door with nothing in hand, from the footer', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('sign-up-link')).toBeTruthy());
    expect(screen.getByTestId('sign-up-link').textContent).toContain('Acme Ltd');
    fireEvent.click(screen.getByTestId('sign-up-link'));

    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());
    expect(screen.queryByTestId('chosen-offer')).toBeNull();
  });

  it('offers no door where there is no organisation to ask', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({
        'GET /api/v1/public/tenant': {
          status: 404,
          error: { error: { code: 'NO_DEFAULT_TENANT', message: 'None.', details: {}, request_id: 'r' } },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('sign-in-link')).toBeTruthy());
    expect(screen.queryByTestId('sign-up-link')).toBeNull();
  });
});

describe('creating the account', () => {
  it('names the organisation at this root and the product on show, and no company', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/tenant': { data: { tenant: ACME } },
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password', name: 'Ada' });

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/auth/sign-up')).toBe(true),
    );

    // The slug from the store — the root the page is on — never typed; the
    // product for the default; and nothing an organisation would be made of.
    const body = requests.find((r) => r.path === '/api/v1/auth/sign-up')?.body;
    expect(body).toEqual({
      email: 'ada@acme.test',
      password: 'a-long-enough-password',
      tenant: 'acme',
      product: 'atlas',
      display_name: 'Ada',
    });
    expect(screen.queryByLabelText(/Company/i)).toBeNull();
    expect(screen.queryByLabelText(/Country/i)).toBeNull();
  });

  it('refuses a password the contract would refuse, before sending it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/tenant': { data: { tenant: ACME } },
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'short' });

    await waitFor(() => expect(screen.getByText(/at least 12 characters/i)).toBeTruthy());
    expect(requests.some((r) => r.path === '/api/v1/auth/sign-up')).toBe(false);
  });

  it('says plainly when the address already has an account', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({
        'POST /api/v1/auth/sign-up': {
          status: 409,
          error: {
            error: { code: 'EMAIL_TAKEN', message: 'Taken.', details: {}, request_id: 'r' },
          },
        },
      }),
    );

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // Unlike every other message about an existing account on this platform.
    // Somebody who cannot be told this cannot finish what they came for.
    await waitFor(() =>
      expect(screen.getByRole('alert').textContent).toMatch(/already has an account/i),
    );
  });

  it("says the organisation's policy in its own words when it refuses", async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({
        'POST /api/v1/auth/sign-up': {
          status: 403,
          error: {
            error: { code: 'JOIN_DOMAIN_NOT_ALLOWED', message: 'No.', details: {}, request_id: 'r' },
          },
        },
      }),
    );

    await chooseTheOffer();
    signUpAs({ email: 'ada@elsewhere.test', password: 'a-long-enough-password' });

    await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/own domain/i));
  });

  it('goes to the root to wait when the membership is pending, with no checkout', async () => {
    const assign = vi.fn();

    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    const { client, requests } = recordingClient({
      'GET /api/v1/public/tenant': { data: { tenant: GUARDED } },
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: { ...CREATED, membership: 'PENDING' } },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // Nothing to buy with yet: the root is where the shell says "waiting",
    // and it is a full navigation, so the session comes back from the cookie.
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
    expect(requests.some((r) => r.path === '/api/v1/checkout/sessions')).toBe(false);
  });

  it('goes to the root when they came in with nothing in hand', async () => {
    const assign = vi.fn();

    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('sign-up-link')).toBeTruthy());
    fireEvent.click(screen.getByTestId('sign-up-link'));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
  });

  it('opens the checkout for what they chose, and pays where the secret was born', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/tenant': { data: { tenant: ACME } },
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
      'POST /api/v1/checkout/sessions': {
        status: 201,
        data: { session: { ...SESSION, client_secret: 'secret-1', payment_provider: STUB_PROVIDER } },
      },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/checkout/sessions')).toBe(true),
    );

    // The offer they chose, not one they are asked to choose again — bought
    // on the session the sign-up issued, as a USER (billing.pay).
    expect(requests.find((r) => r.path === '/api/v1/checkout/sessions')?.body).toEqual({
      offer_id: 'offer-1',
    });

    // No hop yet: the render that received the secret is the one that can
    // offer a card form (ADR-048), and a full navigation would lose it. The
    // stub has no form, so the step says so and points at the order.
    await waitFor(() => expect(screen.getByTestId('payment-panel')).toBeTruthy());
    expect(screen.getByTestId('payment-panel').getAttribute('data-provider')).toBe('stub');
    expect(screen.getByTestId('payment-no-panel')).toBeTruthy();
    expect(screen.getByTestId('sandbox-band')).toBeTruthy();
    expect(screen.getByTestId('continue-to-order').getAttribute('href')).toBe('/checkout/order-1');
  });

  it('goes to the order without a pay step when there is nothing to pay', async () => {
    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({
        'POST /api/v1/checkout/sessions': {
          status: 201,
          data: { session: { ...SESSION, payment_id: null, payment_provider: null } },
        },
      }),
    );

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    await waitFor(() => expect(screen.getByTestId('continue-to-order')).toBeTruthy());
    expect(screen.queryByTestId('payment-panel')).toBeNull();
  });

  it('puts them at the root when it is the checkout that failed', async () => {
    const assign = vi.fn();

    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    renderWith(
      <Storefront onSignIn={() => undefined} />,
      clientFor({
        'POST /api/v1/checkout/sessions': {
          status: 409,
          error: {
            error: { code: 'BILLING_PROFILE_REQUIRED', message: 'No profile.', details: {}, request_id: 'r' },
          },
        },
      }),
    );

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // The account exists by now, so the worst outcome available is the
    // catalogue at the root — where the same purchase is one click away.
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
  });

  it('holds the token without declaring the person signed in, until it navigates', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await chooseTheOffer();
    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // On the pay step now, still on this page.
    await waitFor(() => expect(screen.getByTestId('continue-to-order')).toBeTruthy());

    // The token is usable — the checkout above needed it — and the status has
    // *not* flipped, because `SignInGate` renders the application the instant
    // it does, which would unmount this page mid-purchase.
    expect(useSessionStore.getState().token).toBe('access');
    expect(useSessionStore.getState().status).toBe('anonymous');
  });
});
