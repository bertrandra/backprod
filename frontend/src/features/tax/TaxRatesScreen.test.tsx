import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { recordingClient, renderWith, SESSION, stubClient, type Stubs } from '@/test-utils';

import { TaxRatesScreen } from './TaxRatesScreen';

/**
 * The calculator is a **diagnostic**, and these tests are what makes that more
 * than a claim in a docblock.
 *
 * §25.3's exit criterion is that the screen shows *the rule, rate and regime
 * behind its answer*. A screen that rendered "€25.80" would pass a naive test
 * for "does it calculate" and fail the only question the endpoint exists to
 * answer, which is *why* — so every reasoning field is asserted individually,
 * and the legal mention hardest of all: it is the one sentence with legal effect
 * and the easiest to drop as decoration.
 */
const READER = { ...SESSION, permissions: [...SESSION.permissions, 'tax.read'] };

const RATES = {
  on: '2026-03-15T00:00:00Z',
  rates: [
    {
      country_code: 'FR',
      rate_kind: 'STANDARD',
      basis_points: 2000,
      valid_from: '2014-01-01T00:00:00Z',
      valid_until: null,
      source: 'seed:eu-27',
    },
    {
      country_code: 'FR',
      rate_kind: 'REDUCED',
      basis_points: 550,
      valid_from: '2014-01-01T00:00:00Z',
      valid_until: '2026-01-01T00:00:00Z',
      source: 'seed:eu-27',
    },
  ],
};

const CALCULATION = {
  rule_id: 'eu.b2b.reverse_charge',
  regime: 'REVERSE_CHARGE',
  country_of_taxation: 'DE',
  rate_basis_points: 0,
  taxable_base: 12900,
  vat_amount: 0,
  currency: 'EUR',
  reverse_charge: true,
  customer_tax_status: 'VERIFIED_BUSINESS',
  legal_mention: 'Autoliquidation — article 196 de la directive 2006/112/CE',
  reasons: ['The customer is a verified business in another member state.'],
};

function clientFor(extra: Stubs = {}) {
  return stubClient({
    'GET /api/v1/me': { data: READER },
    'GET /api/v1/tax/rates': { data: RATES },
    ...extra,
  });
}

describe('rates in force', () => {
  it('renders basis points as a rate, half-percents included', async () => {
    renderWith(<TaxRatesScreen />, clientFor());

    await waitFor(() => expect(screen.getByText('20%')).toBeTruthy());
    // 550 basis points, not 5.50% and not 550%: the contract sends basis points
    // precisely so a half is not lost to a parser.
    expect(screen.getByText('5.5%')).toBeTruthy();
  });

  it('says which date the rates were read for', async () => {
    renderWith(<TaxRatesScreen />, clientFor());

    await waitFor(() => expect(screen.getByTestId('rates-as-of')).toBeTruthy());
    expect(screen.getByTestId('rates-as-of').textContent).toMatch(/2026/);
  });

  it('asks the server again when the date changes', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: READER },
      'GET /api/v1/tax/rates': { data: RATES },
    });

    const asked = () =>
      requests
        .filter((request) => request.path === '/api/v1/tax/rates')
        .map((request) => (request.query as { on?: string } | undefined)?.on ?? '(today)');

    renderWith(<TaxRatesScreen />, client);

    await waitFor(() => expect(asked()).toEqual(['(today)']));

    fireEvent.change(screen.getByLabelText('In force on'), { target: { value: '2024-06-01' } });

    // A rate has a validity window, so the date is part of the question — not a
    // filter applied to an answer already fetched.
    await waitFor(() => expect(asked()).toEqual(['(today)', '2024-06-01']));
  });

  it('shows where a figure came from', async () => {
    renderWith(<TaxRatesScreen />, clientFor());

    // A seed, not a fiscal authority — the contract is explicit, so the screen
    // must not present it as authoritative.
    await waitFor(() => expect(screen.getAllByText('seed:eu-27').length).toBe(2));
  });
});

describe('the calculator', () => {
  async function calculate(extra: Stubs = {}) {
    renderWith(
      <TaxRatesScreen />,
      clientFor({ 'POST /api/v1/tax/calculate': { data: { calculation: CALCULATION } }, ...extra }),
    );

    await waitFor(() => expect(screen.getByLabelText('Amount')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '129' } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain it' }));

    await waitFor(() => expect(screen.getByTestId('calculation')).toBeTruthy());
  }

  it('names the rule that decided the answer', async () => {
    await calculate();

    expect(screen.getByTestId('rule-id').textContent).toBe('eu.b2b.reverse_charge');
    expect(screen.getByTestId('calculation').getAttribute('data-rule')).toBe(
      'eu.b2b.reverse_charge',
    );
  });

  it('shows the regime and where the supply is taxed', async () => {
    await calculate();

    expect(screen.getByTestId('regime').textContent).toBe('REVERSE CHARGE');
    expect(screen.getByText(/taxed in DE/)).toBeTruthy();
  });

  it('gives the reasons in words', async () => {
    await calculate();

    expect(screen.getByTestId('reasons').textContent).toMatch(/verified business in another member/i);
  });

  it('carries the legal mention the invoice is obliged to state', async () => {
    await calculate();

    expect(screen.getByTestId('legal-mention').textContent).toMatch(/Autoliquidation/);
  });

  it('says how the customer was read', async () => {
    await calculate();

    expect(screen.getByTestId('customer-status').textContent).toMatch(/VERIFIED_BUSINESS/);
    expect(screen.getByTestId('customer-status').textContent).toMatch(/you account for the VAT/i);
  });

  it('sends minor units, scaled by the currency rather than by 100', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: READER },
      'GET /api/v1/tax/rates': { data: RATES },
      'POST /api/v1/tax/calculate': { data: { calculation: CALCULATION } },
    });

    const bodies = () =>
      requests
        .filter((request) => request.method === 'POST')
        .map((request) => request.body as { amount_minor_units?: number } | undefined);

    renderWith(<TaxRatesScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Amount')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '129.90' } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain it' }));

    await waitFor(() => expect(bodies()).toHaveLength(1));
    expect(bodies()[0]?.amount_minor_units).toBe(12990);

    // JPY has no minor unit, so 129 yen is 129 — dividing or multiplying by 100
    // would ask for a hundred times the money.
    fireEvent.change(screen.getByLabelText('Currency'), { target: { value: 'JPY' } });
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '129' } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain it' }));

    await waitFor(() => expect(bodies()).toHaveLength(2));
    expect(bodies()[1]?.amount_minor_units).toBe(129);
  });

  it('does not send an amount that is not one', async () => {
    const { client, requests } = recordingClient({
      'GET /api/v1/me': { data: READER },
      'GET /api/v1/tax/rates': { data: RATES },
    });

    renderWith(<TaxRatesScreen />, client);

    await waitFor(() => expect(screen.getByLabelText('Amount')).toBeTruthy());
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: 'a lot' } });
    fireEvent.click(screen.getByRole('button', { name: 'Explain it' }));

    await waitFor(() => expect(screen.getByRole('alert').textContent).toMatch(/digits only/i));
    expect(requests.filter((request) => request.method === 'POST')).toHaveLength(0);
  });

  it('omits the mention when the regime requires none, rather than printing an empty one', async () => {
    await calculate({
      'POST /api/v1/tax/calculate': {
        data: {
          calculation: {
            ...CALCULATION,
            regime: 'STANDARD',
            legal_mention: null,
            reverse_charge: false,
            rate_basis_points: 2000,
            vat_amount: 2580,
          },
        },
      },
    });

    expect(screen.queryByTestId('legal-mention')).toBeNull();
    expect(screen.getByTestId('calculated-rate').textContent).toBe('20%');
  });
});
