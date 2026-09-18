import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { StorefrontScreen } from './StorefrontScreen';

/**
 * Deciding what a stranger sees.
 *
 * The thing under test is a distinction, not a toggle: an offer can be on sale
 * and not advertised, and this screen is the only place that difference is
 * visible or changeable. A screen that showed only the advertised ones would
 * be a screen you cannot advertise anything from.
 *
 * The product comes from the switcher in the bar, which on the console lists
 * every product the platform hosts (ADR-047). It once came from `?selected=`
 * in the URL, and before that from the browser's remembered tenant-app product
 * — which meant the console silently administered whichever product the person
 * had last used the *application* in. The switcher is visible, lists the
 * platform's own products, and is the one thing every console screen reads.
 */
const PLAN = { id: 'plan-1', code: 'pro', name: 'Pro', rank: 10 };

const offer = (id: string, code: string, listed: boolean) => ({
  id,
  code,
  name: code,
  plan: PLAN,
  publicly_listed: listed,
  versions: [
    {
      id: `${id}-v1`,
      version: 1,
      status: 'ACTIVE',
      billing_period: 'MONTHLY',
      price: { minor_units: 2900, currency: 'EUR' },
      valid_from: '2026-01-01T00:00:00Z',
      valid_until: null,
      grants: [],
      terms: null,
    },
  ],
});

/** The product being administered travels in the URL, not in an ambient context. */
const ROUTE = {
  path: '/console/storefront',
  initial: '/console/storefront',
} as const;

const ADVERTISED = offer('offer-1', 'pro-monthly', true);
const PRIVATE = offer('offer-2', 'reseller', false);

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/storefront/offers': {
      data: { product: { id: 'p-1', code: 'atlas', name: 'Atlas' }, offers: [ADVERTISED, PRIVATE] },
    },
    'PUT /api/v1/staff/storefront/offers/{offerId}': {
      data: { offer: { ...PRIVATE, publicly_listed: true } },
    },
    ...extra,
  });
}

describe('the storefront console', () => {
  it('lists what is hidden as well as what is not', async () => {
    renderAtRoute(<StorefrontScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());

    // Choosing what to advertise means seeing what you are choosing between.
    expect(document.querySelector('[data-offer="offer-1"]')?.getAttribute('data-advertised')).toBe(
      'true',
    );
    expect(document.querySelector('[data-offer="offer-2"]')?.getAttribute('data-advertised')).toBe(
      'false',
    );
  });

  it('counts what is public, so the answer is readable at a glance', async () => {
    renderAtRoute(<StorefrontScreen />, clientFor(), ROUTE);

    await waitFor(() =>
      // No full stop: the count sits on the title's baseline now, where it is a
      // label rather than a sentence.
      expect(screen.getByTestId('advertised-count').textContent).toBe(
        '1 of 2 advertised publicly',
      ),
    );
  });

  it('says that withdrawing does not stop an offer being sold', async () => {
    renderAtRoute(<StorefrontScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());

    // The distinction is the feature, so the screen states it rather than
    // leaving somebody to discover it by withdrawing a live price.
    expect(screen.getByText(/stays sellable/i)).toBeTruthy();
    expect(screen.getByText(/keeps their terms/i)).toBeTruthy();
  });

  it('sends the state it wants, with the product it is administering', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/storefront/offers': {
        data: {
          product: { id: 'p-1', code: 'atlas', name: 'Atlas' },
          offers: [ADVERTISED, PRIVATE],
        },
      },
      'PUT /api/v1/staff/storefront/offers/{offerId}': {
        data: { offer: { ...PRIVATE, publicly_listed: true } },
      },
    });

    renderAtRoute(<StorefrontScreen />, client, ROUTE);

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Advertise' }));

    await waitFor(() =>
      expect(
        requests.some((r) => r.path === '/api/v1/staff/storefront/offers/{offerId}'),
      ).toBe(true),
    );

    const write = requests.find((r) => r.path === '/api/v1/staff/storefront/offers/{offerId}');

    // A desired state, not a toggle: clicking twice on a slow connection asks
    // for the same thing twice.
    expect(write?.body).toEqual({ publicly_listed: true });
    // A staff route resolves no ambient product, so the console names one.
    expect(write?.query).toEqual({ product: 'atlas' });
  });

  it('offers to withdraw what is already public', async () => {
    renderAtRoute(<StorefrontScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByRole('button', { name: 'Withdraw' })).toBeTruthy());
    expect(screen.getByRole('button', { name: 'Advertise' })).toBeTruthy();
  });

  it('surfaces a refusal instead of looking as though it worked', async () => {
    renderAtRoute(
      <StorefrontScreen />,
      clientFor({
        'PUT /api/v1/staff/storefront/offers/{offerId}': {
          status: 403,
          error: {
            error: { code: 'PERMISSION_DENIED', message: 'Not permitted.', details: {}, request_id: 'r' },
          },
        },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Advertise' }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(document.querySelector('[data-offer="offer-2"]')?.getAttribute('data-advertised')).toBe(
      'false',
    );
  });

  it('says there is nothing to advertise rather than showing an empty list', async () => {
    renderAtRoute(
      <StorefrontScreen />,
      clientFor({
        'GET /api/v1/staff/storefront/offers': {
          data: { product: { id: 'p-1', code: 'atlas', name: 'Atlas' }, offers: [] },
        },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByText(/Nothing to advertise/i)).toBeTruthy());
  });
});

describe('after a sign-up', () => {
  const ADMIN = { staff: { user_id: 's-1', roles: ['PLATFORM_ADMIN'], permissions: ['staff.catalog.manage'] } };
  const SUPPORT = { staff: { user_id: 's-2', roles: ['SUPPORT_ADMIN'], permissions: ['staff.tenants.read'] } };

  it('is offered to whoever administers the storefront, with the setting in force', async () => {
    renderAtRoute(
      <StorefrontScreen />,
      clientFor({
        'GET /api/v1/staff/me': { data: ADMIN },
        'GET /api/v1/staff/storefront/settings': { data: { after_sign_up: 'CATALOGUE' } },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('after-sign-up')).toBeTruthy());
    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>(/The application first/).checked).toBe(true),
    );
  });

  it('is absent for support, and shown even before a product is chosen', async () => {
    renderAtRoute(
      <StorefrontScreen />,
      clientFor({ 'GET /api/v1/staff/me': { data: SUPPORT } }),
      { ...ROUTE, product: null },
    );

    await waitFor(() => expect(screen.getByText('No product chosen')).toBeTruthy());
    expect(screen.queryByTestId('after-sign-up')).toBeNull();
  });

  it('sends the choice as PUT and shows what the server wrote', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/staff/me': { data: ADMIN },
      'GET /api/v1/staff/storefront/settings': { data: { after_sign_up: 'PAY' } },
      'PUT /api/v1/staff/storefront/settings': { data: { after_sign_up: 'CATALOGUE' } },
    });

    renderAtRoute(<StorefrontScreen />, client, { ...ROUTE, product: null });

    await waitFor(() => expect(screen.getByLabelText(/Pay right there/)).toBeTruthy());
    fireEvent.click(screen.getByLabelText(/The application first/));

    await waitFor(() =>
      expect(requests.some((r) => r.method === 'PUT' && r.path === '/api/v1/staff/storefront/settings')).toBe(true),
    );
    expect(requests.find((r) => r.method === 'PUT')?.body).toEqual({ after_sign_up: 'CATALOGUE' });
    await waitFor(() =>
      expect(screen.getByLabelText<HTMLInputElement>(/The application first/).checked).toBe(true),
    );
  });
});
