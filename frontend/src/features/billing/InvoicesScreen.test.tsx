import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { InvoicesScreen } from './InvoicesScreen';

/**
 * U6's first exit criterion: **issuing an invoice shows a real pending state and
 * never a provisional number.**
 *
 * Issuing allocates a gapless legal number. A screen that assumed success would
 * have invented a document, and the number it invented would either collide with
 * a real one or leave a hole in a sequence that is not allowed to have one.
 *
 * So the stub holds the response open, and the test looks at what is on screen
 * *while the request is in flight*. That window is the only place an optimistic
 * implementation differs from this one.
 */
const BILLER = { ...SESSION, permissions: [...SESSION.permissions, 'billing.read', 'billing.manage'] };

function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: 'inv-1',
    number: null,
    status: 'DRAFT',
    final: false,
    subscription_id: null,
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 580, currency: 'EUR' },
    gross: { minor_units: 3480, currency: 'EUR' },
    issued_at: null,
    due_at: null,
    paid_at: null,
    period_start: null,
    period_end: null,
    payment_terms: null,
    supplier: {},
    customer: {},
    lines: [],
    taxes: [],
    ...overrides,
  };
}

const listing = (invoices: unknown[]): Stub => ({
  data: { invoices, total: invoices.length, limit: 25, offset: 0 },
});

function clientFor(invoices: unknown[], extra: Record<string, Stub | (() => Stub)> = {}) {
  return stubClient({
    'GET /api/v1/me': { data: BILLER },
    'GET /api/v1/billing/invoices': listing(invoices),
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<InvoicesScreen />, client, { path: '/invoices' });

describe('issuing an invoice', () => {
  it('shows a pending state and no number while the number is being allocated', async () => {
    render(
      clientFor([], {
        // Held open: this is the window an optimistic implementation would have
        // filled with an invented number.
        'POST /api/v1/billing/invoices': {
          data: invoice({ id: 'inv-9', number: '2026-000042', status: 'ISSUED', final: true }),
          status: 201,
          delayMs: 5_000,
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /issue an invoice/i })).toBeTruthy(),
    );

    fireEvent.click(screen.getByRole('button', { name: /issue an invoice/i }));
    fireEvent.click(screen.getByRole('button', { name: /^issue it$/i }));

    // A real pending state…
    await waitFor(() => expect(screen.getByTestId('issuing')).toBeTruthy());

    // …and nothing that looks like a number. Not a placeholder, not a spinner
    // where a number goes, nothing.
    expect(screen.queryByTestId('invoice-number')).toBeNull();
    expect(document.body.textContent).not.toMatch(/\d{4}-\d{6}/);
  });

  it('shows the number the server allocated, once it has', async () => {
    let issued = 0;

    render(
      stubClient({
        'GET /api/v1/me': { data: BILLER },
        'GET /api/v1/billing/invoices': (): Stub =>
          listing(
            issued === 0
              ? []
              : [invoice({ id: 'inv-9', number: '2026-000042', status: 'ISSUED', final: true })],
          ),
        'POST /api/v1/billing/invoices': (): Stub => {
          issued += 1;

          return {
            data: invoice({ id: 'inv-9', number: '2026-000042', status: 'ISSUED', final: true }),
            status: 201,
          };
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /issue an invoice/i })).toBeTruthy(),
    );

    fireEvent.click(screen.getByRole('button', { name: /issue an invoice/i }));
    fireEvent.click(screen.getByRole('button', { name: /^issue it$/i }));

    await waitFor(() => expect(screen.getByTestId('invoice-number').textContent).toBe('2026-000042'));
  });

  it('is confirmed, and says the allocation cannot be undone', async () => {
    let issued = 0;

    render(
      clientFor([], {
        'POST /api/v1/billing/invoices': (): Stub => {
          issued += 1;

          return { data: invoice({ number: '2026-000001' }), status: 201 };
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /issue an invoice/i })).toBeTruthy(),
    );

    expect(screen.getByText(/must have no gaps/i)).toBeTruthy();
    expect(screen.getByText(/corrected by a credit note/i)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /issue an invoice/i }));
    expect(issued).toBe(0);

    fireEvent.click(screen.getByRole('button', { name: /^not yet$/i }));
    expect(issued).toBe(0);
  });
});

describe('a draft', () => {
  it('says it has no number rather than showing a placeholder', async () => {
    render(clientFor([invoice()]));

    await waitFor(() => expect(screen.getByTestId('no-number')).toBeTruthy());
    expect(screen.getByTestId('no-number').textContent).toMatch(/a draft has none/i);
    expect(screen.queryByTestId('invoice-number')).toBeNull();
  });
});

describe('the money', () => {
  it('comes from minor units, never from a float', async () => {
    render(clientFor([invoice({ number: '2026-000001', status: 'ISSUED' })]));

    await waitFor(() =>
      expect(document.querySelector('[data-minor-units="3480"]')).not.toBeNull(),
    );
    expect(document.querySelector('[data-minor-units="2900"]')).not.toBeNull();
    expect(document.querySelector('[data-minor-units="580"]')).not.toBeNull();
  });
});

describe('someone who may only read', () => {
  it('sees the invoices and cannot issue one', async () => {
    renderAtRoute(
      <InvoicesScreen />,
      stubClient({
        'GET /api/v1/me': { data: { ...SESSION, permissions: ['billing.read'] } },
        'GET /api/v1/billing/invoices': listing([invoice({ number: '2026-000001' })]),
      }),
      { path: '/invoices' },
    );

    await waitFor(() => expect(screen.getByTestId('invoice-number')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /issue an invoice/i })).toBeNull();
  });
});

/**
 * Whose each one is (2026-09-26).
 *
 * `billing.manage` shows an administrator every invoice the organisation has,
 * and until this the rows carried an amount, a status and a number and nothing
 * that told one colleague's from another's.
 */
describe('the row says who it is for', () => {
  it('names the person a seat was sold to, and the organisation that sold it', async () => {
    render(
      clientFor([
        invoice({
          number: '2026-000004',
          customer: {
            legal_name: 'Ada Lovelace',
            person: { name: 'Ada Lovelace', email: 'ada@acme.test' },
            organisation: 'Acme',
          },
        }),
      ]),
    );

    await waitFor(() => expect(screen.getByTestId('invoice-for')).toBeTruthy());

    const whose = screen.getByTestId('invoice-for');

    expect(whose.textContent).toContain('Ada Lovelace');
    expect(whose.textContent).toContain('ada@acme.test');
    expect(whose.textContent).toContain('Acme');
  });

  it('reads the snapshot and never a live row', async () => {
    // The party is who they were when the document was raised, which is the
    // whole reason it was copied (§25). Nothing on this screen asks the
    // server who the tenant is called today.
    const client = clientFor([invoice({ customer: { legal_name: 'Acme, as it was then' } })]);

    render(client);

    await waitFor(() =>
      expect(screen.getByTestId('invoice-for').textContent).toContain('Acme, as it was then'),
    );
  });

  it('says nobody is recorded rather than drawing a dash', async () => {
    render(clientFor([invoice()]));

    await waitFor(() => expect(screen.getByTestId('invoice-for')).toBeTruthy());

    // A dash reads as a name that failed to load.
    expect(screen.getByTestId('invoice-for').getAttribute('data-whose')).toBe('unrecorded');
  });
});
