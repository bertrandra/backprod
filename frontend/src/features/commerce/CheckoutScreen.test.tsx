import { fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { POLL_MS } from '@/queries/checkout';
import { recordingClient, renderAtRoute, SESSION, stubClient, type Stub, type Stubs } from '@/test-utils';

// Stripe's SDK, replaced: what is under test is that a fresh attempt's form
// is offered on this page, not what Stripe does inside it.
vi.mock('@stripe/stripe-js', () => ({ loadStripe: vi.fn(() => Promise.resolve({ confirmPayment: vi.fn() })) }));
vi.mock('@stripe/react-stripe-js', () => ({
  Elements: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  PaymentElement: () => <div data-testid="stripe-payment-element" />,
  useStripe: () => ({ confirmPayment: vi.fn() }),
  useElements: () => ({}),
}));

import { CheckoutScreen } from './CheckoutScreen';

/**
 * U5's first exit criterion: **a checkout whose connection drops leaves a
 * findable order, and the UI leads back to it.**
 *
 * The test for that is this screen rendering from nothing but the id in the URL.
 * There is no session state to restore, because a checkout session *is* an order
 * (ADR-034) — so "recovering" is just reading it, which is what a reload does.
 */
const BUYER = { ...SESSION, permissions: [...SESSION.permissions, 'billing.manage', 'billing.pay'] };

const ORDER_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

function session(overrides: Record<string, unknown> = {}) {
  return {
    id: ORDER_ID,
    order_id: ORDER_ID,
    status: 'AWAITING_PAYMENT',
    invoice_id: null,
    subscription_id: null,
    payment_id: null,
    payment_status: null,
    net: { minor_units: 2900, currency: 'EUR' },
    vat: { minor_units: 580, currency: 'EUR' },
    gross: { minor_units: 3480, currency: 'EUR' },
    description: 'Pro monthly (v1)',
    seat: false,
    ...overrides,
  };
}

function stubsFor(stub: Stub | (() => Stub), extra: Stubs = {}): Stubs {
  return {
    'GET /api/v1/me': { data: BUYER },
    'GET /api/v1/checkout/sessions/{sessionId}': stub,
    ...extra,
  };
}

function clientFor(stub: Stub | (() => Stub), extra: Stubs = {}) {
  return stubClient(stubsFor(stub, extra));
}

const render = (client: ReturnType<typeof stubClient>) =>
  renderAtRoute(<CheckoutScreen sessionId={ORDER_ID} />, client, {
    path: '/checkout/$sessionId',
    initial: `/checkout/${ORDER_ID}`,
  });

describe('a checkout opened from a link', () => {
  it('renders from the id alone — nothing was kept in the tab', async () => {
    render(clientFor({ data: { session: session() } }));

    await waitFor(() => expect(screen.getByTestId('checkout-status')).toBeTruthy());
    expect(screen.getByText(/this is order/i)).toBeTruthy();
    expect(document.querySelector('[data-minor-units="3480"]')).not.toBeNull();
  });

  it('says what was bought, and for whom', async () => {
    // Three amounts and two ids said what it cost and not what it was
    // (2026-09-18): the order's own line does, and `seat` says whose.
    render(clientFor({ data: { session: session() } }));

    await waitFor(() => expect(screen.getByTestId('checkout-description')).toBeTruthy());
    expect(screen.getByTestId('checkout-description').textContent).toContain('Pro monthly (v1)');
    expect(screen.getByTestId('checkout-description').textContent).toContain('for the organisation');
    expect(screen.getByTestId('checkout-description').getAttribute('data-seat')).toBe('false');
  });

  it('says a seat is the person’s own', async () => {
    render(clientFor({ data: { session: session({ seat: true }) } }));

    await waitFor(() => expect(screen.getByTestId('checkout-description')).toBeTruthy());
    expect(screen.getByTestId('checkout-description').textContent).toContain('your own seat');
    expect(screen.getByTestId('checkout-description').getAttribute('data-seat')).toBe('true');
  });

  it('says the id is the order’s, and links to the order list', async () => {
    render(clientFor({ data: { session: session() } }));

    const link = await waitFor(() =>
      screen.getByRole<HTMLAnchorElement>('link', { name: /all orders/i }),
    );

    expect(link.getAttribute('href')).toContain('/orders');
    expect(screen.getByText(ORDER_ID)).toBeTruthy();
  });
});

describe('while the money has not arrived', () => {
  it('says it is waiting, and keeps asking', async () => {
    let polls = 0;

    render(
      clientFor((): Stub => {
        polls += 1;

        return { data: { session: session() } };
      }),
    );

    await waitFor(() => expect(screen.getByTestId('checkout-waiting')).toBeTruthy());
    // Activation is payment-gated and the payment arrives through a webhook, so
    // the page has to ask rather than wait to be told.
    await waitFor(() => expect(polls).toBeGreaterThan(1), { timeout: 5_000 });
  });

  it('shows the two steps of the gate, neither of them done', async () => {
    render(clientFor({ data: { session: session() } }));

    await waitFor(() => expect(screen.getByTestId('step-invoice')).toBeTruthy());
    expect(screen.getByTestId('step-subscription').textContent).toMatch(/not started/i);
    expect(screen.getByTestId('step-subscription').textContent).toMatch(
      /before the money arrives/i,
    );
  });
});

describe('when it completes', () => {
  it('stops asking', async () => {
    let polls = 0;

    render(
      clientFor((): Stub => {
        polls += 1;

        return {
          data: {
            session: session({
              status: 'COMPLETED',
              invoice_id: 'inv-1',
              subscription_id: 'sub-1',
            }),
          },
        };
      }),
    );

    await waitFor(() => expect(screen.getByTestId('step-subscription').textContent).toContain('sub-1'));
    // And says so in words, at the top: "When doing my check out I do not
    // see I did purchase" was the operator's report (2026-09-18).
    expect(screen.getByTestId('checkout-completed').textContent).toMatch(/paid/i);
    expect(screen.getByTestId('checkout-completed').textContent).toMatch(/subscription has started/i);

    const after = polls;
    // Longer than the interval, deliberately: a 250 ms wait proved nothing,
    // because no poll could have fired in it either way. This version fails when
    // the stop condition is removed.
    await new Promise((resolve) => setTimeout(resolve, POLL_MS + 500));

    // Finished is finished: asking again would be asking about the past.
    expect(polls).toBe(after);
    expect(screen.queryByTestId('checkout-waiting')).toBeNull();
  });
});

describe('when the payment was collected but the sale could not start', () => {
  it('says so, and stops waiting', async () => {
    let polls = 0;

    render(
      clientFor((): Stub => {
        polls += 1;

        return { data: { session: session({ status: 'HELD', payment_status: 'SUCCEEDED' }) } };
      }),
    );

    await waitFor(() => expect(screen.getByTestId('payment-held')).toBeTruthy());

    // The truthful sentence is neither "awaiting" nor "completed".
    expect(screen.getByTestId('checkout-status').getAttribute('data-status')).toBe('HELD');
    expect(screen.getByText(/will be refunded/i)).toBeTruthy();
    expect(screen.queryByTestId('checkout-waiting')).toBeNull();

    const after = polls;
    await new Promise((resolve) => setTimeout(resolve, POLL_MS + 500));

    expect(polls).toBe(after);
  });
});

describe('when the payment failed', () => {
  it('says the order survives and that a retry is a new attempt', async () => {
    render(clientFor({ data: { session: session({ status: 'PAYMENT_FAILED' }) } }));

    await waitFor(() => expect(screen.getByTestId('payment-failed')).toBeTruthy());

    // Both halves, honestly: nothing is lost, and the credential is gone.
    expect(screen.getByText(/order is intact/i)).toBeTruthy();
    expect(screen.getByText(/cannot be resumed/i)).toBeTruthy();
  });

  it('does not keep asking — nothing is coming without somebody acting', async () => {
    let polls = 0;

    render(
      clientFor((): Stub => {
        polls += 1;

        return { data: { session: session({ status: 'PAYMENT_FAILED' }) } };
      }),
    );

    await waitFor(() => expect(screen.getByTestId('payment-failed')).toBeTruthy());

    const after = polls;
    await new Promise((resolve) => setTimeout(resolve, POLL_MS + 500));

    expect(polls).toBe(after);
  });
});

describe('an unpaid checkout', () => {
  // The buyer closed the card form and is back at the order (2026-09-18):
  // it must offer to pay, and to give up.
  const STARTED = {
    id: 'pay-2',
    invoice_id: 'inv-1',
    provider: 'stripe',
    provider_payment_id: 'pi_2',
    status: 'PENDING',
    settled: false,
    final: false,
    amount: { minor_units: 3480, currency: 'EUR' },
    method: 'card',
    failure_code: null,
    failure_reason: null,
    succeeded_at: null,
    failed_at: null,
    created_at: '2026-01-03T08:59:00Z',
    client_secret: 'pi_2_secret_never_stored',
    payment_provider: { name: 'stripe', publishable_key: 'pk_test_1', sandbox: true },
  };

  it('offers a fresh attempt, and the card form where its secret was born', async () => {
    const { client, requests } = recordingClient(
      stubsFor({ data: { session: session({ invoice_id: 'inv-1', payment_id: 'pay-1', payment_status: 'PENDING' }) } }, {
        'POST /api/v1/billing/invoices/{invoiceId}/payments': { status: 201, data: STARTED },
      }),
    );
    render(client);

    await waitFor(() => expect(screen.getByTestId('pay-now')).toBeTruthy());
    fireEvent.click(screen.getByTestId('pay-now'));

    // A new attempt on the invoice — never the old one revived.
    await waitFor(() => expect(requests.some((r) => r.path === '/api/v1/billing/invoices/{invoiceId}/payments')).toBe(true));
    await waitFor(() => expect(screen.getByTestId('checkout-pay')).toBeTruthy());
    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
  });

  it('offers to give it up, asks first, and shows the order cancelled afterwards', async () => {
    const { client, requests } = recordingClient(
      stubsFor({ data: { session: session({ invoice_id: 'inv-1', payment_id: 'pay-1', payment_status: 'PENDING' }) } }, {
        'POST /api/v1/checkout/sessions/{sessionId}/cancel': {
          data: { session: session({ status: 'CANCELLED', invoice_id: 'inv-1', payment_id: 'pay-1', payment_status: 'PENDING' }) },
        },
      }),
    );
    render(client);

    await waitFor(() => expect(screen.getByRole('button', { name: /cancel this purchase…/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /cancel this purchase…/i }));
    expect(requests.some((r) => r.path === '/api/v1/checkout/sessions/{sessionId}/cancel')).toBe(false);

    fireEvent.click(screen.getByTestId('cancel-checkout'));

    await waitFor(() => expect(screen.getByTestId('checkout-cancelled')).toBeTruthy());
    expect(screen.getByTestId('checkout-status').getAttribute('data-status')).toBe('CANCELLED');
    // And nothing more to do: both ways out are gone with the order.
    expect(screen.queryByTestId('checkout-actions')).toBeNull();
  });

  it('offers the same two ways out after a failed attempt', async () => {
    render(clientFor({ data: { session: session({ status: 'PAYMENT_FAILED', invoice_id: 'inv-1', payment_id: 'pay-1', payment_status: 'FAILED' }) } }));

    await waitFor(() => expect(screen.getByTestId('payment-failed')).toBeTruthy());
    expect(screen.getByTestId('pay-now')).toBeTruthy();
    expect(screen.getByRole('button', { name: /cancel this purchase…/i })).toBeTruthy();
  });

  it('offers neither once it is paid', async () => {
    render(clientFor({ data: { session: session({ status: 'COMPLETED', invoice_id: 'inv-1', subscription_id: 'sub-1' }) } }));

    await waitFor(() => expect(screen.getByTestId('checkout-completed')).toBeTruthy());
    expect(screen.queryByTestId('checkout-actions')).toBeNull();
  });

  it('offers neither to someone who may not pay', async () => {
    render(
      stubClient({
        'GET /api/v1/me': { data: { ...SESSION, permissions: ['billing.read'] } },
        'GET /api/v1/checkout/sessions/{sessionId}': { data: { session: session({ invoice_id: 'inv-1' }) } },
      }),
    );

    await waitFor(() => expect(screen.getByTestId('checkout-status')).toBeTruthy());
    expect(screen.queryByTestId('checkout-actions')).toBeNull();
  });
});

describe('the client secret', () => {
  it('never appears on this screen, because the read does not carry one', async () => {
    // It is returned once, by `openCheckoutSession`, and deliberately not stored
    // anywhere a reload could find it. The read has no such field at all.
    render(clientFor({ data: { session: session() } }));

    await waitFor(() => expect(screen.getByTestId('checkout-status')).toBeTruthy());

    expect(document.body.textContent).not.toMatch(/secret/i);
    expect(window.localStorage.getItem('client_secret')).toBeNull();
    expect(window.location.search).not.toMatch(/secret/i);
  });
});
