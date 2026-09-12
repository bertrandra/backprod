import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, stubClient, type Stubs } from '@/test-utils';

import { ReadinessScreen } from './ReadinessScreen';

/**
 * The map of the maze.
 *
 * What this screen owes is narrow: keep the chain in dependency order even when
 * steps are done, point at exactly one next thing, and never compute readiness
 * itself — the backend answers through the ports a sale reads, and a second
 * opinion here is how a console comes to say ready where a checkout refuses.
 */
const PRODUCT = { id: 'p-1', code: 'atlas', name: 'Atlas' };

const step = (
  key: string,
  done: boolean,
  blocking: boolean,
  detail: Record<string, unknown> = {},
) => ({ key, done, blocking, detail });

const FRESH = {
  product: PRODUCT,
  steps: [
    step('product', true, true, { code: 'atlas', active: true }),
    step('billing_identity', false, true, { missing: ['legal_name', 'country_code'] }),
    step('tax', false, false, {}),
    step('plans', false, true, { count: 0 }),
    step('features', false, false, { count: 0 }),
    step('offers', false, true, { count: 0 }),
    step('published', false, true, { count: 0 }),
    step('advertised', false, true, { count: 0 }),
    step('payments', true, true, {}),
  ],
  sellable: false,
  next: 'billing_identity',
};

const READY = {
  ...FRESH,
  steps: FRESH.steps.map((s) => ({ ...s, done: true })),
  sellable: true,
  next: null,
};

const ROUTE = { path: '/console/readiness', initial: '/console/readiness?selected=atlas' } as const;

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/staff/products': { data: { products: [{ ...PRODUCT, active: true }] } },
    'GET /api/v1/staff/readiness': { data: FRESH },
    ...extra,
  });
}

describe('a product that cannot sell yet', () => {
  it('says how many steps are left rather than only that something is wrong', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), ROUTE);

    const banner = await waitFor(() => screen.getByTestId('not-sellable'));

    // Five blocking steps are undone in the fixture.
    expect(banner.textContent).toMatch(/5 steps left/i);
  });

  it('marks exactly one step as the next thing to do', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('setup-chain')).toBeTruthy());

    expect(screen.getAllByTestId('do-this-next')).toHaveLength(1);
    expect(document.querySelector('[data-next="true"]')?.getAttribute('data-step')).toBe(
      'billing_identity',
    );
  });

  it('names the missing fields, which is the audit half', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), ROUTE);

    const missing = await waitFor(() => screen.getByTestId('missing-billing_identity'));

    // The same field names a checkout refuses over, so the person fixing it is
    // not comparing a form against a specification.
    expect(missing.textContent).toMatch(/legal_name, country_code/);
  });

  it('keeps the chain in dependency order, not sorted by state', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('setup-chain')).toBeTruthy());

    // `product` and `payments` are done and stay where they are. Sorting the
    // done ones away would move the shape of the chain under somebody halfway
    // through it — and would stop the order teaching the order.
    const keys = [...document.querySelectorAll('[data-step]')].map((el) =>
      el.getAttribute('data-step'),
    );

    expect(keys).toEqual([
      'product',
      'billing_identity',
      'tax',
      'plans',
      'features',
      'offers',
      'published',
      'advertised',
      'payments',
    ]);
  });

  it('tells required apart from optional', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), ROUTE);

    await waitFor(() => expect(screen.getByTestId('setup-chain')).toBeTruthy());

    // A supplier selling at home invoices correctly without a stated tax
    // position; calling it required would send somebody to do work the
    // platform does not need.
    expect(screen.getByTestId('state-tax').textContent).toBe('optional');
    expect(screen.getByTestId('state-features').textContent).toBe('optional');
    expect(screen.getByTestId('state-plans').textContent).toBe('required');
    expect(screen.getByTestId('state-product').textContent).toBe('done');
  });

  it('offers no button for the one step a screen cannot fix', async () => {
    renderAtRoute(
      <ReadinessScreen />,
      clientFor({
        'GET /api/v1/staff/readiness': {
          data: {
            ...FRESH,
            steps: FRESH.steps.map((s) => (s.key === 'payments' ? { ...s, done: false } : s)),
          },
        },
      }),
      ROUTE,
    );

    await waitFor(() => expect(screen.getByTestId('setup-chain')).toBeTruthy());

    const payments = document.querySelector('[data-step="payments"]');

    // A payment provider is the deployment's configuration. A button leading to
    // a screen that cannot change it would be worse than saying so.
    expect(payments?.querySelector('button')).toBeNull();
    expect(payments?.textContent).toMatch(/environment/i);
  });
});

describe('a product that can sell', () => {
  it('says so, in terms of what a stranger can do', async () => {
    renderAtRoute(
      <ReadinessScreen />,
      clientFor({ 'GET /api/v1/staff/readiness': { data: READY } }),
      ROUTE,
    );

    const banner = await waitFor(() => screen.getByTestId('sellable'));

    expect(banner.textContent).toMatch(/no account/i);
    expect(screen.queryByTestId('do-this-next')).toBeNull();
  });
});

describe('choosing a product', () => {
  it('uses the only product without asking, because one option is not a choice', async () => {
    renderAtRoute(<ReadinessScreen />, clientFor(), { path: '/console/readiness' });

    // No ?selected= in the URL, one product on the platform.
    await waitFor(() => expect(screen.getByTestId('setup-chain')).toBeTruthy());
    expect(screen.getByText(/Setting up Atlas/i)).toBeTruthy();
  });

  it('asks when there are several, because the console has no ambient product', async () => {
    renderAtRoute(
      <ReadinessScreen />,
      clientFor({
        'GET /api/v1/staff/products': {
          data: {
            products: [
              { ...PRODUCT, active: true },
              { id: 'p-2', code: 'orbit', name: 'Orbit', active: true },
            ],
          },
        },
      }),
      { path: '/console/readiness' },
    );

    await waitFor(() => expect(screen.getByText(/Choose a product/i)).toBeTruthy());
  });

  it('points at Products when the platform hosts none', async () => {
    renderAtRoute(
      <ReadinessScreen />,
      clientFor({ 'GET /api/v1/staff/products': { data: { products: [] } } }),
      { path: '/console/readiness' },
    );

    await waitFor(() => expect(screen.getByText(/No product yet/i)).toBeTruthy());
    expect(screen.getByRole('link', { name: /Go to Products/i })).toBeTruthy();
  });
});
