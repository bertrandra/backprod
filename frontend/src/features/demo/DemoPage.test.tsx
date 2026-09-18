import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

import { DemoPage } from './DemoPage';

/**
 * The demonstration page: off is one sentence, on is the whole picture —
 * products with their offers, organisations with a link to their root,
 * their holdings, subscriptions and people with roles.
 */
const CONTENTS = {
  products: [
    {
      code: 'atlas',
      name: 'Atlas',
      offers: [
        { code: 'pro-monthly', name: 'Pro monthly', plan: 'pro', billing_period: 'MONTHLY', price: { minor_units: 2900, currency: 'EUR' }, publicly_listed: true },
        { code: 'reseller', name: 'Reseller terms', plan: 'pro', billing_period: 'YEARLY', price: { minor_units: 29000, currency: 'EUR' }, publicly_listed: false },
      ],
    },
    { code: 'boreas', name: 'Boreas', offers: [] },
  ],
  tenants: [
    {
      slug: 'acme',
      name: 'Acme Ltd',
      is_default: true,
      join_policy: 'OPEN',
      products: ['atlas', 'boreas'],
      subscriptions: [{ product: 'atlas', offer: 'Pro monthly', plan: 'Pro', status: 'ACTIVE' }],
      members: [
        { display_name: 'Ada Lovelace', email: 'ada@acme.test', roles: ['TENANT_ADMIN'] },
        { display_name: null, email: 'grace@acme.test', roles: ['USER'] },
      ],
    },
    { slug: 'globex', name: 'Globex', is_default: false, join_policy: 'APPROVAL', products: ['atlas'], subscriptions: [], members: [] },
  ],
};

describe('the demonstration page', () => {
  it('says there is none when the switch is off', async () => {
    renderWith(
      <DemoPage />,
      stubClient({
        'GET /api/v1/public/demo': { status: 404, error: { error: { code: 'DEMO_PAGE_OFF', message: 'No.', details: {}, request_id: 'r' } } },
      }),
      { product: null },
    );

    await waitFor(() => expect(screen.getByTestId('demo-off')).toBeTruthy());
    expect(screen.getByText(/No demonstration page/)).toBeTruthy();
  });

  it('shows products with their offers, and organisations with a link to their root', async () => {
    renderWith(<DemoPage />, stubClient({ 'GET /api/v1/public/demo': { data: CONTENTS } }), { product: null });

    await waitFor(() => expect(screen.getByTestId('demo-page')).toBeTruthy());

    const atlas = screen.getByTestId('demo-products').querySelector('[data-demo-product="atlas"]');
    expect(atlas?.textContent).toContain('Pro monthly');
    expect(atlas?.textContent).toContain('not advertised');
    expect(atlas?.querySelector('[data-minor-units="2900"]')).not.toBeNull();
    expect(screen.getByTestId('demo-products').textContent).toContain('Nothing on sale');

    // The default organisation lives at the bare host, the other at its slug.
    expect(screen.getByTestId('demo-home-acme').getAttribute('href')).toBe('/');
    expect(screen.getByTestId('demo-home-globex').getAttribute('href')).toBe('/globex/');

    const acme = screen.getByTestId('demo-tenants').querySelector('[data-demo-tenant="acme"]');
    expect(acme?.textContent).toContain('atlas, boreas');
    expect(acme?.textContent).toContain('Pro monthly');
    expect(acme?.textContent).toContain('Ada Lovelace');
    expect(acme?.textContent).toContain('TENANT_ADMIN');
    expect(acme?.textContent).toContain('grace@acme.test');
    expect(acme?.textContent).toContain('USER');
    expect(acme?.textContent).toContain('joins by open');
  });
});
