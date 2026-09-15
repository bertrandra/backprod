import { fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { InvoiceScreen } from './InvoiceScreen';

// Stripe.js replaced: what the panel does around it is PaymentElementPanel's
// test; here it only has to appear where the secret was born.
vi.mock('@stripe/stripe-js', () => ({ loadStripe: vi.fn(() => Promise.resolve({ confirmPayment: vi.fn() })) }));
vi.mock('@stripe/react-stripe-js', () => ({
  Elements: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  PaymentElement: () => <div data-testid="stripe-payment-element" />,
  useStripe: () => ({ confirmPayment: vi.fn() }),
  useElements: () => ({}),
}));

/**
 * U6's fourth exit criterion, as far as a test without a live backend can carry
 * it: **amounts and VAT rates match the invoice PDF exactly.**
 *
 * They match by construction rather than by comparison. ADR-035 says the PDF is
 * rendered once, at issue, from the invoice document — the same document this
 * screen renders. So what is asserted here is that the screen renders *that*
 * document faithfully: every line's net, its rate in basis points, every tax row
 * and every total, all from the API and none of them computed here. A real
 * page-against-paper diff needs a live backend and belongs in U9.
 *
 * The fixture is built so that arithmetic would give a different answer: the
 * line nets do **not** sum to the invoice net. A screen that totalled the lines
 * itself would show 5800 where the document says 2900.
 */
const BILLER = {
  ...SESSION,
  permissions: [...SESSION.permissions, 'billing.read', 'billing.manage', 'payments.manage'],
};

const INVOICE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function line(position: number, overrides: Record<string, unknown> = {}) {
  return {
    position,
    description: `Line ${String(position)}`,
    quantity: 1,
    unit_price: { minor_units: 2900, currency: 'EUR' },
    discount: { minor_units: 0, currency: 'EUR' },
    net: { minor_units: 2900, currency: 'EUR' },
    vat_rate_basis_points: 550,
    vat: { minor_units: 160, currency: 'EUR' },
    gross: { minor_units: 3060, currency: 'EUR' },
    source_offer_version_id: 'ver-1',
    ...overrides,
  };
}

function invoice(overrides: Record<string, unknown> = {}) {
  return {
    id: INVOICE_ID,
    number: '2026-000042',
    status: 'ISSUED',
    final: true,
    subscription_id: null,
    // Deliberately not the sum of the lines below.
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 160, currency: 'EUR' },
    gross: { minor_units: 3060, currency: 'EUR' },
    issued_at: '2026-01-01T10:00:00Z',
    due_at: '2026-01-31T10:00:00Z',
    paid_at: null,
    period_start: null,
    period_end: null,
    payment_terms: null,
    supplier: { legal_name: 'Backprod SAS', vat_number: 'FR123', city: 'Lille' },
    customer: { legal_name: 'Acme Ltd', vat_number: 'GB999', city: 'Leeds' },
    lines: [line(1), line(2)],
    taxes: [
      {
        jurisdiction: 'FR',
        rate_basis_points: 550,
        taxable: { minor_units: 2900, currency: 'EUR' },
        tax: { minor_units: 160, currency: 'EUR' },
      },
    ],
    ...overrides,
  };
}

function clientFor(extra: Record<string, Stub | (() => Stub)> = {}, inv = invoice()) {
  return stubClient({
    'GET /api/v1/me': { data: BILLER },
    'GET /api/v1/billing/invoices/{invoiceId}': { data: inv },
    'GET /api/v1/billing/invoices/{invoiceId}/transmissions': { data: { transmissions: [] } },
    'GET /api/v1/billing/invoices/{invoiceId}/pdf': { data: new Blob(['%PDF-']) },
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<InvoiceScreen invoiceId={INVOICE_ID} />, client, {
    path: '/invoices/$invoiceId',
    initial: `/invoices/${INVOICE_ID}`,
  });

describe('the document on screen', () => {
  it('renders every total from the invoice, not from its lines', async () => {
    render(clientFor());

    await waitFor(() => expect(screen.getByTestId('invoice-number')).toBeTruthy());

    // The invoice says 2900 net. The two lines say 2900 each. A screen that
    // summed them would render 5800 somewhere — and there is no such element.
    expect(document.querySelectorAll('[data-minor-units="5800"]')).toHaveLength(0);
    expect(document.querySelectorAll('[data-minor-units="2900"]').length).toBeGreaterThan(0);
    expect(document.querySelector('[data-minor-units="3060"]')).not.toBeNull();
  });

  it('renders each line’s VAT rate from its basis points', async () => {
    render(clientFor());

    await waitFor(() => expect(screen.getByTestId('rate-1')).toBeTruthy());

    // 550 basis points, with the half intact.
    expect(screen.getByTestId('rate-1').textContent).toBe('5.5%');
    expect(screen.getByTestId('rate-2').textContent).toBe('5.5%');
  });

  it('shows one tax row per jurisdiction and rate', async () => {
    render(clientFor());

    await waitFor(() => expect(screen.getByText(/FR at 5\.5%/)).toBeTruthy());
  });

  it('shows the parties as they were at issue', async () => {
    // Copied onto the document, not referenced — a later change of address must
    // not rewrite an invoice already sent.
    render(clientFor());

    await waitFor(() => expect(screen.getByTestId('supplier')).toBeTruthy());
    expect(screen.getByTestId('supplier').textContent).toContain('Backprod SAS');
    expect(screen.getByTestId('customer').textContent).toContain('Acme Ltd');
    expect(screen.getAllByText(/as recorded when this was issued/i).length).toBe(2);
  });
});

describe('the stored PDF', () => {
  it('is offered once the invoice has a number', async () => {
    render(clientFor());

    await waitFor(() => expect(screen.getByTestId('pdf-download')).toBeTruthy());
  });

  it('is not asked for at all while the invoice has none', async () => {
    let asked = 0;

    render(
      clientFor(
        {
          'GET /api/v1/billing/invoices/{invoiceId}/pdf': (): Stub => {
            asked += 1;

            return { data: new Blob(['%PDF-']) };
          },
        },
        invoice({ number: null, status: 'DRAFT', final: false }),
      ),
    );

    await waitFor(() => expect(screen.getByTestId('no-document')).toBeTruthy());

    // Asking would answer 409 INVOICE_NOT_RENDERABLE: a draft has no document.
    expect(asked).toBe(0);
    expect(screen.queryByTestId('pdf-download')).toBeNull();
  });

  it('is fetched once and kept — the bytes never change', async () => {
    let asked = 0;

    render(
      clientFor({
        'GET /api/v1/billing/invoices/{invoiceId}/pdf': (): Stub => {
          asked += 1;

          return { data: new Blob(['%PDF-']) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('pdf-download')).toBeTruthy());
    expect(asked).toBe(1);

    // Rendered once at issue and stored (ADR-035), so a refetch could only ever
    // return the same bytes.
    fireEvent.click(screen.getByTestId('pdf-download'));
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(asked).toBe(1);
  });
});

describe('what can be done to it', () => {
  it('offers a credit note on a final invoice, and no way to edit it', async () => {
    render(clientFor());

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /issue a credit note/i })).toBeTruthy(),
    );

    // A final invoice is corrected, never edited. No cancel either, and no
    // input anywhere that could change a term.
    expect(screen.queryByRole('button', { name: /cancel the invoice/i })).toBeNull();
    expect(document.querySelectorAll('table input')).toHaveLength(0);

    // The explanation appears with the action it explains.
    fireEvent.click(screen.getByRole('button', { name: /issue a credit note…/i }));
    expect(screen.getByText(/its own document, with its own number/i)).toBeTruthy();
  });

  it('offers cancelling only while it can still change', async () => {
    render(clientFor({}, invoice({ number: null, status: 'DRAFT', final: false })));

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /cancel the invoice/i })).toBeTruthy(),
    );
    expect(screen.getByTestId('not-final')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /issue a credit note/i })).toBeNull();
  });

  it('issues a credit note through the server, never locally', async () => {
    let credited = 0;

    render(
      clientFor({
        'POST /api/v1/billing/invoices/{invoiceId}/credit': (): Stub => {
          credited += 1;

          return { data: { id: 'cn-1' }, status: 201 };
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /issue a credit note/i })).toBeTruthy(),
    );

    fireEvent.click(screen.getByRole('button', { name: /issue a credit note…/i }));
    fireEvent.click(screen.getByRole('button', { name: /^issue the credit note$/i }));

    await waitFor(() => expect(credited).toBe(1));
  });

  it('takes a payment and offers the card form right there, for the invoice\'s gross', async () => {
    const started = {
      id: 'pay-1',
      invoice_id: INVOICE_ID,
      subscription_id: null,
      provider: 'stripe',
      provider_payment_id: 'pi_1',
      status: 'PENDING',
      settled: false,
      final: false,
      amount: { minor_units: 3060, currency: 'EUR' },
      method: null,
      failure_code: null,
      failure_reason: null,
      succeeded_at: null,
      failed_at: null,
      created_at: '2026-09-16T10:00:00Z',
      client_secret: 'pi_1_secret',
      payment_provider: { name: 'stripe', publishable_key: 'pk_test_1', sandbox: true },
    };

    render(clientFor({ 'POST /api/v1/billing/invoices/{invoiceId}/payments': { data: started, status: 201 } }));

    await waitFor(() => expect(screen.getByRole('button', { name: /take a payment/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /take a payment/i }));

    // Where the secret was born (ADR-048), and gone from the actions row
    // while the form is up — one attempt at a time.
    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
    expect(screen.getByTestId('sandbox-band')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /take a payment/i })).toBeNull();
    expect(screen.getByRole('button', { name: /^Pay/ }).querySelector('[data-minor-units="3060"]')).not.toBeNull();
    // The secret went to the provider's SDK and is written nowhere.
    expect(document.body.textContent).not.toContain('pi_1_secret');
  });
});

describe('e-invoicing', () => {
  it('draws the progression with the current state named', async () => {
    render(
      clientFor({
        'GET /api/v1/billing/invoices/{invoiceId}/transmissions': {
          data: {
            transmissions: [
              {
                id: 't-1',
                invoice_id: INVOICE_ID,
                provider: 'chorus',
                provider_document_id: 'doc-1',
                status: 'SUBMITTED',
                settled: false,
                rejection_code: null,
                rejection_reason: null,
                submitted_at: '2026-01-01T11:00:00Z',
                settled_at: null,
                created_at: '2026-01-01T10:59:00Z',
              },
            ],
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('progression')).toBeTruthy());
    expect(screen.getByTestId('transmission-state').textContent).toBe('SUBMITTED');

    // Reached: PENDING and SUBMITTED. Not yet: ACCEPTED.
    expect(document.querySelector('[data-step="PENDING"]')?.getAttribute('data-reached')).toBe('true');
    expect(document.querySelector('[data-step="SUBMITTED"]')?.getAttribute('data-reached')).toBe('true');
    expect(document.querySelector('[data-step="ACCEPTED"]')?.getAttribute('data-reached')).toBe('false');
  });

  it('shows a rejection as a settled outcome rather than a step', async () => {
    render(
      clientFor({
        'GET /api/v1/billing/invoices/{invoiceId}/transmissions': {
          data: {
            transmissions: [
              {
                id: 't-2',
                invoice_id: INVOICE_ID,
                provider: 'chorus',
                provider_document_id: null,
                status: 'REJECTED',
                settled: true,
                rejection_code: 'SIRET_UNKNOWN',
                rejection_reason: 'The recipient SIRET is not registered.',
                submitted_at: '2026-01-01T11:00:00Z',
                settled_at: '2026-01-01T11:05:00Z',
                created_at: '2026-01-01T10:59:00Z',
              },
            ],
          },
        },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('rejection')).toBeTruthy());

    expect(screen.getByTestId('rejection').textContent).toContain('SIRET_UNKNOWN');
    expect(screen.getByTestId('rejection').textContent).toMatch(/refused it/i);
    // Not drawn as a fourth step: it is where the journey stopped, not how far
    // it got.
    expect(screen.queryByTestId('progression')).toBeNull();
  });
});
