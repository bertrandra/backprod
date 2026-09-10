import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, stubClient, type Stubs } from '@/test-utils';

import { MetricsScreen } from './MetricsScreen';

/**
 * Three distinctions the numbers here are worthless without.
 *
 *   - **a settled month against one still moving** — `closed`. The same figure
 *     means two different things on the 2nd and the 31st;
 *   - **credits beside turnover, never subtracted from it** — netting them off
 *     produces a third number matching neither the ledger nor the invoices;
 *   - **"nothing came up for renewal" against "nothing renewed"** — `measured`.
 *     Nothing auto-renews yet (R11), so a bare 0% would report an unbuilt feature
 *     as total churn.
 */
const PRODUCTS = { products: [{ id: 'p-1', code: 'atlas', name: 'Atlas' }] };

const METRICS = {
  product_id: 'p-1',
  months: 12,
  turnover: [
    {
      month: '2026-03-01',
      currency: 'EUR',
      net_minor_units: 500_000,
      vat_minor_units: 100_000,
      gross_minor_units: 600_000,
      credited_minor_units: 40_000,
      invoices_issued: 12,
      invoices_paid: 10,
      closed: true,
    },
    {
      month: '2026-04-01',
      currency: 'EUR',
      net_minor_units: 120_000,
      vat_minor_units: 24_000,
      gross_minor_units: 144_000,
      credited_minor_units: 0,
      invoices_issued: 3,
      invoices_paid: 1,
      closed: false,
    },
  ],
  top_offers: {
    month: '2026-03',
    offers: [
      {
        offer_id: 'o-1',
        code: 'pro-monthly',
        name: 'Pro, monthly',
        currency: 'EUR',
        net_minor_units: 300_000,
        lines_billed: 8,
      },
    ],
  },
  renewal: [
    { month: '2026-03-01', due: 4, renewed: 3, ended: 1, rate_percent: 75, measured: true },
    { month: '2026-04-01', due: 0, renewed: 0, ended: 0, rate_percent: null, measured: false },
  ],
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/products': { data: PRODUCTS },
    'GET /api/v1/admin/metrics': { data: METRICS },
    ...extra,
  });
}

describe('a month', () => {
  it('says whether it is settled or still moving', async () => {
    renderWith(<MetricsScreen />, clientFor());

    await waitFor(() =>
      expect(document.querySelector('[data-turnover-month="2026-03-01"]')).not.toBeNull(),
    );

    expect(
      document.querySelector('[data-turnover-month="2026-03-01"]')?.getAttribute('data-closed'),
    ).toBe('true');
    expect(
      document.querySelector('[data-turnover-month="2026-04-01"]')?.getAttribute('data-closed'),
    ).toBe('false');

    const states = screen.getAllByTestId('month-state').map((node) => node.textContent);

    expect(states).toEqual(['settled', 'still moving']);
  });
});

describe('credits', () => {
  it('sit beside turnover and are never subtracted from it', async () => {
    renderWith(<MetricsScreen />, clientFor());

    await waitFor(() =>
      expect(document.querySelector('[data-minor-units="600000"]')).not.toBeNull(),
    );

    // Gross as sent, and the credit shown separately. 560000 would be the
    // netted figure — a third number matching neither the ledger nor the
    // invoices.
    expect(document.querySelector('[data-minor-units="40000"]')).not.toBeNull();
    expect(document.querySelector('[data-minor-units="560000"]')).toBeNull();
    expect(screen.getByText(/never subtracted from it/i)).toBeTruthy();
  });
});

describe('renewal', () => {
  it('reports a rate where something came up', async () => {
    renderWith(<MetricsScreen />, clientFor());

    await waitFor(() =>
      expect(document.querySelector('[data-renewal-month="2026-03-01"]')).not.toBeNull(),
    );
    expect(
      document.querySelector('[data-renewal-month="2026-03-01"] [data-testid="renewal-rate"]')
        ?.textContent,
    ).toBe('75%');
  });

  it('says nothing came up rather than reporting 0%', async () => {
    renderWith(<MetricsScreen />, clientFor());

    await waitFor(() =>
      expect(document.querySelector('[data-renewal-month="2026-04-01"]')).not.toBeNull(),
    );

    const unmeasured = document.querySelector('[data-renewal-month="2026-04-01"]');

    expect(unmeasured?.getAttribute('data-measured')).toBe('false');
    expect(unmeasured?.querySelector('[data-testid="renewal-rate"]')?.textContent).toBe(
      'nothing came up',
    );
    // 0% would report an unbuilt feature as total churn.
    expect(unmeasured?.textContent).not.toMatch(/0%/);
  });

  it('does not treat a measured zero as unmeasured', async () => {
    renderWith(
      <MetricsScreen />,
      clientFor({
        'GET /api/v1/admin/metrics': {
          data: {
            ...METRICS,
            renewal: [
              { month: '2026-05-01', due: 6, renewed: 0, ended: 6, rate_percent: 0, measured: true },
            ],
          },
        },
      }),
    );

    // Six came up and none renewed: that *is* 0%, and saying "nothing came up"
    // would hide real churn.
    await waitFor(() => expect(screen.getByTestId('renewal-rate').textContent).toBe('0%'));
  });
});

describe('top offers', () => {
  it('names the month they were ranked within', async () => {
    renderWith(<MetricsScreen />, clientFor());

    await waitFor(() =>
      expect(screen.getByTestId('offers-month').textContent).toMatch(/within 2026-03/),
    );
    expect(screen.getByText('Pro, monthly')).toBeTruthy();
  });
});

describe('the product', () => {
  it('is one at a time, with no combined figure offered', async () => {
    renderWith(<MetricsScreen />, clientFor());

    // Waited on an option, not on the label: the select exists from the first
    // render and is empty until the product list lands.
    await waitFor(() =>
      expect(screen.getByLabelText<HTMLSelectElement>('Product').options).toHaveLength(1),
    );

    const options = Array.from(screen.getByLabelText<HTMLSelectElement>('Product').options).map(
      (option) => option.textContent,
    );

    // No "All products": different currencies and different catalogues do not
    // add up, and offering the option would invite somebody to quote it.
    expect(options).toEqual(['Atlas']);
    expect(screen.getByText(/no combined figure across products/i)).toBeTruthy();
  });
});
