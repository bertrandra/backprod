import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, stubClient, type Stubs } from '@/test-utils';

import { StorefrontScreen } from './StorefrontScreen';

/**
 * Deciding what a stranger sees.
 *
 * The thing under test is a distinction, not a toggle: an offer can be on sale
 * and not advertised, and this screen is the only place that difference is
 * visible or changeable. A screen that showed only the advertised ones would
 * be a screen you cannot advertise anything from.
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
    renderWith(<StorefrontScreen />, clientFor());

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
    renderWith(<StorefrontScreen />, clientFor());

    await waitFor(() =>
      expect(screen.getByTestId('advertised-count').textContent).toBe(
        '1 of 2 advertised publicly.',
      ),
    );
  });

  it('says that withdrawing does not stop an offer being sold', async () => {
    renderWith(<StorefrontScreen />, clientFor());

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

    renderWith(<StorefrontScreen />, client);

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
    renderWith(<StorefrontScreen />, clientFor());

    await waitFor(() => expect(screen.getByRole('button', { name: 'Withdraw' })).toBeTruthy());
    expect(screen.getByRole('button', { name: 'Advertise' })).toBeTruthy();
  });

  it('surfaces a refusal instead of looking as though it worked', async () => {
    renderWith(
      <StorefrontScreen />,
      clientFor({
        'PUT /api/v1/staff/storefront/offers/{offerId}': {
          status: 403,
          error: {
            error: { code: 'PERMISSION_DENIED', message: 'Not permitted.', details: {}, request_id: 'r' },
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('storefront-offers')).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: 'Advertise' }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(document.querySelector('[data-offer="offer-2"]')?.getAttribute('data-advertised')).toBe(
      'false',
    );
  });

  it('says there is nothing to advertise rather than showing an empty list', async () => {
    renderWith(
      <StorefrontScreen />,
      clientFor({
        'GET /api/v1/staff/storefront/offers': {
          data: { product: { id: 'p-1', code: 'atlas', name: 'Atlas' }, offers: [] },
        },
      }),
    );

    await waitFor(() => expect(screen.getByText(/Nothing to advertise/i)).toBeTruthy());
  });
});
