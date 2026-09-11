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

const CREATED = {
  access_token: 'access',
  token_type: 'Bearer',
  expires_in: 3600,
  tenant_id: 'tenant-1',
};

const SESSION = { id: 'order-1', status: 'AWAITING_PAYMENT', payment_id: 'pay-1' };

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/public/offers': { data: WINDOW },
    'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
    'POST /api/v1/checkout/sessions': { status: 201, data: { session: SESSION } },
    ...extra,
  });
}

/** Fills the form the way a person does, and submits it. */
function signUpAs(fields: { email: string; password: string; organisation?: string }): void {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: fields.email } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: fields.password } });

  if (fields.organisation !== undefined) {
    fireEvent.change(screen.getByLabelText('Company (optional)'), {
      target: { value: fields.organisation },
    });
  }

  fireEvent.click(screen.getByRole('button', { name: /Create account and continue/i }));
}

beforeEach(() => {
  window.localStorage.clear();
  useSessionStore.setState({ token: null, productCode: 'atlas', status: 'anonymous', expiresAt: null });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('the shop window', () => {
  it('shows prices to somebody with no account', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());

    expect(screen.getByText('Pro, monthly')).toBeTruthy();
    expect(screen.getByText('€29.00')).toBeTruthy();
  });

  it('asks for the product it was told about, not for a list of them', async () => {
    const { client, requests } = recordingClient({ 'GET /api/v1/public/offers': { data: WINDOW } });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(requests.length).toBeGreaterThan(0));

    // The product is a filter the caller names. Which products a deployment
    // hosts is not something a public page gets to enumerate, so there is no
    // call that would answer it.
    expect(requests[0]?.path).toBe('/api/v1/public/offers');
    expect(requests[0]?.query).toEqual({ product: 'atlas' });
    expect(requests.some((r) => r.path === '/api/v1/products')).toBe(false);
  });

  it('says so plainly when nothing named a product', () => {
    // The screen rather than the container, deliberately: `useProductContext`
    // falls back to the configured default, so this state is reached by a
    // deployment that set none — which is a property of the screen's input and
    // not something the container can be talked into.
    renderWith(
      <StorefrontScreen productCode={null} onChoose={() => undefined} onSignIn={() => undefined} />,
      clientFor(),
    );

    // Not an error to apologise for, and not an empty shop: the person reading
    // it cannot fix it, so it says who can and how.
    expect(screen.getByText(/No product selected/i)).toBeTruthy();
    expect(screen.getByText(/VITE_DEFAULT_PRODUCT/)).toBeTruthy();
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
  it('asks for an account rather than for a sign-in', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));

    await waitFor(() => expect(screen.getByText(/Create your account/i)).toBeTruthy());
  });

  it('keeps the chosen offer and its price in front of them while they type', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));

    await waitFor(() => expect(screen.getByTestId('chosen-offer')).toBeTruthy());

    // A purchase that turns out to cost something else is the complaint this
    // avoids.
    expect(screen.getByTestId('chosen-offer').textContent).toContain('Pro, monthly');
    expect(screen.getByTestId('chosen-offer').textContent).toContain('€29.00');
  });

  it('lets them go back and choose differently', async () => {
    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));

    await waitFor(() => expect(screen.getByTestId('choose-another')).toBeTruthy());
    fireEvent.click(screen.getByTestId('choose-another'));

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
  });
});

describe('creating the account', () => {
  it('sends the company when there is one', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
      'POST /api/v1/checkout/sessions': { status: 201, data: { session: SESSION } },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password', organisation: 'Acme Ltd' });

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/auth/sign-up')).toBe(true),
    );

    const body = requests.find((r) => r.path === '/api/v1/auth/sign-up')?.body;

    expect(body).toMatchObject({
      email: 'ada@acme.test',
      product: 'atlas',
      organisation: 'Acme Ltd',
    });
  });

  it('sends no company at all when somebody is buying for themselves', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
      'POST /api/v1/checkout/sessions': { status: 201, data: { session: SESSION } },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'sam@personal.test', password: 'a-long-enough-password' });

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/auth/sign-up')).toBe(true),
    );

    // Null, not "". The B2C case is the short one and the screen must not
    // assert an empty company name it does not mean.
    expect(requests.find((r) => r.path === '/api/v1/auth/sign-up')?.body).toMatchObject({
      organisation: null,
    });
  });

  it('refuses a password the contract would refuse, before sending it', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

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

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // Unlike every other message about an existing account on this platform.
    // Somebody who cannot be told this cannot finish what they came for.
    await waitFor(() =>
      expect(screen.getByRole('alert').textContent).toMatch(/already has an account/i),
    );
  });

  it('goes straight into the checkout for what they chose', async () => {
    const assign = vi.fn();

    // The hop into the application is a real navigation, because the
    // storefront renders outside the router — so this is what there is to
    // assert on.
    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    const { client, requests } = recordingClient({
      'GET /api/v1/public/offers': { data: WINDOW },
      'POST /api/v1/auth/sign-up': { status: 201, data: CREATED },
      'POST /api/v1/checkout/sessions': { status: 201, data: { session: SESSION } },
    });

    renderWith(<Storefront onSignIn={() => undefined} />, client);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    await waitFor(() =>
      expect(requests.some((r) => r.path === '/api/v1/checkout/sessions')).toBe(true),
    );

    // The offer they chose, not one they are asked to choose again.
    expect(requests.find((r) => r.path === '/api/v1/checkout/sessions')?.body).toEqual({
      offer_id: 'offer-1',
    });
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/checkout/order-1'));
  });

  it('puts them in the application when it is the checkout that failed', async () => {
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

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    // The account exists by now, so the worst outcome available is the
    // catalogue — where the same purchase is one click away. Telling somebody
    // their sign-up failed would send them to create a second account, which
    // the first would then refuse.
    await waitFor(() => expect(assign).toHaveBeenCalledWith('/catalogue'));
  });

  it('holds the token without declaring the person signed in, until it navigates', async () => {
    const assign = vi.fn();

    vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, assign });

    renderWith(<Storefront onSignIn={() => undefined} />, clientFor());

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Choose' }));
    await waitFor(() => expect(screen.getByLabelText('Email')).toBeTruthy());

    signUpAs({ email: 'ada@acme.test', password: 'a-long-enough-password' });

    await waitFor(() => expect(assign).toHaveBeenCalled());

    // The token is usable — the checkout above needed it — and the status has
    // *not* flipped, because `SignInGate` renders the application the instant
    // it does, which would unmount this page mid-purchase. An E2E run found
    // that; this is what keeps it fixed.
    expect(useSessionStore.getState().token).toBe('access');
    expect(useSessionStore.getState().status).toBe('anonymous');
  });
});
