import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWith, SESSION, stubClient, type Stub } from '@/test-utils';

import { CreditNotesScreen } from './CreditNotesScreen';

/**
 * A credit note is a **document**, not an invoice status.
 *
 * Its own number, from its own sequence. `direction` is always `CREDIT` and the
 * contract explains why it is there: so a client reading a mixed ledger never
 * has to infer the sign. The test insists it is shown rather than turned into a
 * minus, because inferring is exactly what it exists to prevent.
 */
const BILLER = { ...SESSION, permissions: [...SESSION.permissions, 'billing.read'] };

const NOTE = {
  id: 'cn-1',
  number: 'CN-2026-000007',
  invoice_id: 'inv-1',
  direction: 'CREDIT',
  reason: 'Duplicate charge',
  net: { minor_units: 2900, currency: 'EUR' },
  vat: { minor_units: 160, currency: 'EUR' },
  gross: { minor_units: 3060, currency: 'EUR' },
  issued_at: '2026-02-01T10:00:00Z',
  supplier: {},
  customer: {},
  lines: [
    {
      position: 1,
      description: 'Pro monthly',
      quantity: 1,
      unit_price: { minor_units: 2900, currency: 'EUR' },
      discount: { minor_units: 0, currency: 'EUR' },
      net: { minor_units: 2900, currency: 'EUR' },
      vat_rate_basis_points: 550,
      vat: { minor_units: 160, currency: 'EUR' },
      gross: { minor_units: 3060, currency: 'EUR' },
      source_offer_version_id: null,
    },
  ],
};

const listing = (notes: unknown[]): Stub => ({
  data: { credit_notes: notes, total: notes.length, limit: 25, offset: 0 },
});

function clientFor(notes: unknown[]) {
  return stubClient({
    'GET /api/v1/me': { data: BILLER },
    'GET /api/v1/billing/credit-notes': listing(notes),
  });
}

describe('a credit note', () => {
  it('has its own number, from its own sequence', async () => {
    renderWith(<CreditNotesScreen />, clientFor([NOTE]));

    await waitFor(() =>
      expect(screen.getByTestId('credit-note-number').textContent).toBe('CN-2026-000007'),
    );
    expect(screen.getByText(/its own sequence/i)).toBeTruthy();
  });

  it('states its direction rather than leaving a sign to be inferred', async () => {
    renderWith(<CreditNotesScreen />, clientFor([NOTE]));

    await waitFor(() => expect(screen.getByTestId('direction').textContent).toBe('CREDIT'));
    // Not rendered as a negative amount: the amounts are positive and the
    // direction says what they mean.
    expect(document.querySelector('[data-minor-units="3060"]')).not.toBeNull();
    expect(document.querySelector('[data-minor-units="-3060"]')).toBeNull();
  });

  it('names the invoice it corrects, which keeps its own number', async () => {
    renderWith(<CreditNotesScreen />, clientFor([NOTE]));

    await waitFor(() => expect(screen.getByText(/corrects invoice/i)).toBeTruthy());
    expect(screen.getByText('inv-1')).toBeTruthy();
    expect(screen.getByText(/keeps its own number and totals/i)).toBeTruthy();
  });

  it('renders its lines with the rate from basis points', async () => {
    renderWith(<CreditNotesScreen />, clientFor([NOTE]));

    await waitFor(() => expect(screen.getByText(/VAT 5\.5%/)).toBeTruthy());
  });
});

describe('with none', () => {
  it('explains where one comes from', async () => {
    renderWith(<CreditNotesScreen />, clientFor([]));

    await waitFor(() => expect(screen.getByText(/no credit notes/i)).toBeTruthy());
    expect(screen.getByText(/from an invoice that needs correcting/i)).toBeTruthy();
  });
});
