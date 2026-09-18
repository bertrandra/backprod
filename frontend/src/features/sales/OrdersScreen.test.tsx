import { fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { OrdersScreen } from './OrdersScreen';

/**
 * The payment gate, read straight off the document (non-negotiable #20).
 *
 * An `invoice_id` from fulfilment onwards; a `subscription_id` only once that
 * invoice is paid. The tests below insist the two are shown as *separate* facts,
 * because a single "paid ✓" would erase the difference — and the difference is
 * whether the customer actually has what they bought.
 */
const SELLER = { ...SESSION, permissions: [...SESSION.permissions, 'sales.read', 'sales.manage'] };

function order(overrides: Record<string, unknown> = {}) {
  return {
    id: 'o-1',
    status: 'PENDING',
    quote_id: null,
    offer_version_id: 'v-1',
    subscription_id: null,
    invoice_id: null,
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 580, currency: 'EUR' },
    gross: { minor_units: 3480, currency: 'EUR' },
    completed_at: null,
    created_at: '2026-01-01T00:00:00Z',
    lines: [],
    ...overrides,
  };
}

const listing = (orders: unknown[]): Stub => ({
  data: { orders, total: orders.length, limit: 25, offset: 0 },
});

function clientFor(
  orders: unknown[],
  extra: Record<string, Stub | (() => Stub)> = {},
  session: Record<string, unknown> = SELLER,
) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/sales/orders': listing(orders),
    ...extra,
  });
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<OrdersScreen />, client, { path: '/orders' });

describe('the payment gate', () => {
  it('shows nothing invoiced and nothing provisioned on a new order', async () => {
    render(clientFor([order()]));

    await waitFor(() => expect(screen.getByTestId('payment-gate')).toBeTruthy());
    expect(screen.getByTestId('gate-invoice').textContent).toMatch(/not invoiced/i);
    expect(screen.getByTestId('gate-subscription').textContent).toMatch(/not provisioned/i);
  });

  it('shows an invoice without implying a subscription', async () => {
    // The state fulfilment produces, and the one #20 exists to keep visible:
    // money is owed, nothing is provisioned.
    render(clientFor([order({ status: 'AWAITING_PAYMENT', invoice_id: 'inv-1' })]));

    await waitFor(() => expect(screen.getByTestId('gate-invoice').textContent).toMatch(/invoiced/i));
    // A link to the document, not its id in the text (2026-09-19).
    expect(screen.getByTestId('gate-invoice').querySelector('a')?.getAttribute('href')).toContain('inv-1');
    expect(screen.getByTestId('gate-invoice').textContent).not.toContain('inv-1');
    expect(screen.getByTestId('gate-subscription').textContent).toMatch(/not provisioned/i);
    expect(screen.getByTestId('gate-subscription').textContent).toMatch(
      /before the invoice is paid/i,
    );
  });

  it('shows both once the money has arrived', async () => {
    render(
      clientFor([
        order({ status: 'COMPLETED', invoice_id: 'inv-1', subscription_id: 'sub-1' }),
      ]),
    );

    await waitFor(() =>
      expect(screen.getByTestId('gate-subscription').textContent).toMatch(/subscription started/i),
    );
    expect(screen.getByTestId('gate-subscription').querySelector('a')?.getAttribute('href')).toContain('/subscription');
    expect(screen.getByTestId('gate-invoice').querySelector('a')?.getAttribute('href')).toContain('inv-1');
    // And no id in words anywhere on the card: they were nobody's to read.
    expect(screen.getByTestId('gate-subscription').textContent).not.toContain('sub-1');
  });

  it('says what was bought, and for whom', async () => {
    render(
      clientFor([
        order({
          seat: true,
          lines: [
            {
              position: 1,
              description: 'Pro monthly (v1) — subscription',
              quantity: 1,
              unit_price: { minor_units: 2900, currency: 'EUR' },
              discount: { minor_units: 0, currency: 'EUR' },
              net: { minor_units: 2900, currency: 'EUR' },
              vat_rate_basis_points: 2000,
              vat: { minor_units: 580, currency: 'EUR' },
              gross: { minor_units: 3480, currency: 'EUR' },
              source_offer_version_id: 'v-1',
              offer: { product: { code: 'atlas', name: 'Atlas' }, code: 'pro-monthly', name: 'Pro monthly', plan: 'Pro', billing_period: 'MONTHLY', version: 1 },
            },
          ],
        }),
      ]),
    );

    await waitFor(() => expect(screen.getByTestId('order-what')).toBeTruthy());
    expect(screen.getByTestId('order-what').textContent).toContain('Atlas');
    expect(screen.getByTestId('order-what').textContent).toContain('Pro monthly');
    expect(screen.getByTestId('order-what').textContent).toMatch(/billed monthly/i);
    expect(screen.getByTestId('order-for').textContent).toMatch(/for yourself/i);
  });
});

describe('fulfilling', () => {
  it('is offered only while there is no invoice', async () => {
    render(clientFor([order({ status: 'AWAITING_PAYMENT', invoice_id: 'inv-1' })]));

    await waitFor(() => expect(screen.getByTestId('payment-gate')).toBeTruthy());
    // Already invoiced — fulfilling again is not a thing to offer.
    expect(screen.queryByRole('button', { name: /^fulfil$/i })).toBeNull();
  });

  it('asks the server and shows what it answered', async () => {
    let fulfilled = 0;

    // The list is the row's source, so the stub is stateful: after fulfilment the
    // server answers with the invoiced order. That is what makes this a test of
    // the invalidation — a screen that patched the row locally would pass even
    // with the list stub frozen, and would then be wrong the moment the server
    // disagreed.
    render(
      stubClient({
        'GET /api/v1/me': { data: SELLER },
        'GET /api/v1/sales/orders': (): Stub =>
          listing([
            fulfilled === 0
              ? order()
              : order({ status: 'AWAITING_PAYMENT', invoice_id: 'inv-9' }),
          ]),
        'POST /api/v1/sales/orders/{orderId}/fulfil': (): Stub => {
          fulfilled += 1;

          return { data: order({ status: 'AWAITING_PAYMENT', invoice_id: 'inv-9' }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /^fulfil$/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /^fulfil$/i }));

    await waitFor(() => expect(fulfilled).toBe(1));
    // The invoice came from the server, not from this screen.
    await waitFor(() => expect(screen.getByTestId('gate-invoice').querySelector('a')?.getAttribute('href')).toContain('inv-9'));
    expect(screen.getByTestId('gate-subscription').textContent).toMatch(/not provisioned/i);
  });
});

describe('cancelling', () => {
  it('is confirmed, and says it cannot be undone', async () => {
    let cancelled = 0;

    render(
      clientFor([order()], {
        'POST /api/v1/sales/orders/{orderId}/cancel': (): Stub => {
          cancelled += 1;

          return { data: order({ status: 'CANCELLED' }) };
        },
      }),
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /cancel order/i })).toBeTruthy());

    fireEvent.click(screen.getByRole('button', { name: /cancel order/i }));
    expect(cancelled).toBe(0);
    expect(screen.getByText(/cannot be undone/i)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: /cancel permanently/i }));
    await waitFor(() => expect(cancelled).toBe(1));
  });

  it('is not offered on an order that is already finished', async () => {
    render(clientFor([order({ status: 'COMPLETED', invoice_id: 'i', subscription_id: 's' })]));

    await waitFor(() => expect(screen.getByTestId('payment-gate')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /cancel order/i })).toBeNull();
  });
});

describe('an order awaiting payment', () => {
  it('links to its checkout, which is the same id', async () => {
    render(clientFor([order({ status: 'AWAITING_PAYMENT', invoice_id: 'inv-1' })]));

    const link = await waitFor(() =>
      screen.getByRole<HTMLAnchorElement>('link', { name: /open the checkout/i }),
    );

    // A checkout session is an order (ADR-034): one id, so the dropped-connection
    // case is a link away rather than lost.
    expect(link.getAttribute('href')).toContain('/checkout/o-1');
  });
});

describe('someone who may only read', () => {
  it('sees the orders and none of the actions', async () => {
    render(clientFor([order()], {}, { ...SESSION, permissions: ['sales.read'] }));

    await waitFor(() => expect(screen.getByTestId('payment-gate')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /^fulfil$/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /cancel order/i })).toBeNull();
  });
});
