import { fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { renderAtRoute, SESSION, stubClient, type Stub } from '@/test-utils';

import { PaymentsScreen } from './PaymentsScreen';

// Stripe.js replaced: what the panel does around it is PaymentElementPanel's
// test; here it only has to appear, or not.
vi.mock('@stripe/stripe-js', () => ({ loadStripe: vi.fn(() => Promise.resolve({ confirmPayment: vi.fn() })) }));
vi.mock('@stripe/react-stripe-js', () => ({
  Elements: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  PaymentElement: () => <div data-testid="stripe-payment-element" />,
  useStripe: () => ({ confirmPayment: vi.fn() }),
  useElements: () => ({}),
}));

/** The retry's answer: the new attempt, with its one-time secret beside it. */
const newAttempt = (provider: unknown = { name: 'stub', publishable_key: null, sandbox: true }) => ({
  ...payment({ id: 'pay-2', status: 'PENDING', failure_code: null, failure_reason: null }),
  client_secret: 'pi_secret_new_attempt',
  payment_provider: provider,
});

/**
 * U6's second exit criterion: **a failed payment leads to retry, and the UI makes
 * clear it is a new attempt.**
 *
 * Not a resurrection. The failed payment keeps its status and its failure code
 * because it is the record of what happened, and the screen has to say so — a
 * person who thinks their previous attempt is resuming will not understand why
 * the card is asked for again.
 */
const PAYER = { ...SESSION, permissions: [...SESSION.permissions, 'payments.read', 'payments.manage', 'billing.pay'] };

function payment(overrides: Record<string, unknown> = {}) {
  return {
    id: 'pay-1',
    invoice_id: 'inv-1',
    subscription_id: null,
    provider: 'stripe',
    provider_payment_id: 'pi_1',
    status: 'FAILED',
    settled: false,
    final: true,
    amount: { minor_units: 3480, currency: 'EUR' },
    method: 'card',
    failure_code: 'card_declined',
    failure_reason: 'The card was declined.',
    succeeded_at: null,
    failed_at: '2026-01-01T10:00:00Z',
    created_at: '2026-01-01T09:59:00Z',
    ...overrides,
  };
}

const listing = (payments: unknown[]): Stub => ({
  data: { payments, total: payments.length, limit: 25, offset: 0 },
});

function clientFor(
  payments: unknown[],
  extra: Record<string, Stub | (() => Stub)> = {},
  session: Record<string, unknown> = PAYER,
) {
  return stubClient({
    'GET /api/v1/me': { data: session },
    'GET /api/v1/billing/payments': listing(payments),
    ...extra,
  });
}

describe('a failed payment', () => {
  it('shows why, with both the reason and the code', async () => {
    // Different questions: the reason is what to tell the person, the code is
    // what to quote to the provider.
    renderAtRoute(<PaymentsScreen />, clientFor([payment()]), { path: '/payments' });

    await waitFor(() => expect(screen.getByTestId('failure')).toBeTruthy());
    expect(screen.getByTestId('failure').textContent).toContain('The card was declined.');
    expect(screen.getByTestId('failure').textContent).toContain('card_declined');
  });

  it('says when it was started and when it failed, to the minute, and what it was for', async () => {
    // A date alone did not answer somebody who paid twice that day
    // (2026-09-19): each moment carries its time, and the invoice is a link.
    renderAtRoute(<PaymentsScreen />, clientFor([payment()]), { path: '/payments' });

    await waitFor(() => expect(screen.getByTestId('payment-details')).toBeTruthy());
    expect(screen.getByTestId('payment-started').getAttribute('datetime')).toBe('2026-01-01T09:59:00Z');
    expect(screen.getByTestId('payment-failed-at').getAttribute('datetime')).toBe('2026-01-01T10:00:00Z');
    // The time, not only the date: the two moments read differently.
    expect(screen.getByTestId('payment-started').textContent).not.toBe(screen.getByTestId('payment-failed-at').textContent);
    expect(screen.getByTestId('payment-details').textContent).toContain('card via stripe');
    expect(screen.getByTestId('payment-details').querySelector('a')?.getAttribute('href')).toContain('inv-1');
    expect(screen.getByTestId('payment-details').textContent).toContain('pi_1');
  });

  it('offers a retry that says it is a new attempt', async () => {
    let retried = 0;

    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment()], {
        'POST /api/v1/payments/{paymentId}/retry': (): Stub => {
          retried += 1;

          return { data: newAttempt(), status: 201 };
        },
      }),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /try again/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));

    await waitFor(() => expect(retried).toBe(1));

    // The words that matter: new attempt, this one stays failed, the card is
    // asked for again.
    await waitFor(() => expect(screen.getByTestId('new-attempt')).toBeTruthy());
    expect(screen.getByTestId('new-attempt').textContent).toMatch(/new/i);
    expect(screen.getByTestId('new-attempt').textContent).toMatch(/stays failed/i);
    expect(screen.getByTestId('new-attempt').textContent).toMatch(/cannot be resumed/i);
  });

  it('offers the card form right there when the provider has one', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment()], {
        'POST /api/v1/payments/{paymentId}/retry': {
          data: newAttempt({ name: 'stripe', publishable_key: 'pk_test_1', sandbox: true }),
          status: 201,
        },
      }),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /try again/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));

    // Where the secret was born (ADR-048): the form is under the attempt
    // that failed, for the new one, with the new attempt's amount.
    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
    expect(screen.getByTestId('sandbox-band')).toBeTruthy();
    expect(screen.getByRole('button', { name: /^Pay/ }).querySelector('[data-minor-units="3480"]')).not.toBeNull();
  });

  it('never puts the new credential anywhere it could be found', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment()], {
        'POST /api/v1/payments/{paymentId}/retry': {
          data: newAttempt(),
          status: 201,
        },
      }),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /try again/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));

    await waitFor(() => expect(screen.getByTestId('new-attempt')).toBeTruthy());

    // Returned "here and nowhere else", and short-lived. It goes to the
    // provider's SDK and is forgotten.
    expect(document.body.textContent).not.toContain('pi_secret');
    expect(JSON.stringify(window.localStorage)).not.toContain('pi_secret');
    expect(window.location.href).not.toContain('pi_secret');
  });
});

describe('a succeeded payment', () => {
  it('can be refunded, with a reason chosen rather than typed', async () => {
    let refunded: string | null = null;

    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment({ status: 'SUCCEEDED', settled: true, failure_code: null, failure_reason: null })], {
        'POST /api/v1/billing/payments/{paymentId}/refund': (): Stub => {
          refunded = 'yes';

          return { data: {}, status: 202 };
        },
      }),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /refund/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /refund…/i }));

    // A select, not a text box: the API stores the reason as a category, and a
    // free-text field produces forty spellings of "duplicate".
    const reason = await waitFor(() => screen.getByLabelText<HTMLSelectElement>(/^reason$/i));
    expect(reason.tagName).toBe('SELECT');

    fireEvent.click(screen.getByRole('button', { name: /refund it/i }));
    await waitFor(() => expect(refunded).toBe('yes'));
  });

  it('says the money leaves asynchronously', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment({ status: 'SUCCEEDED', settled: true, failure_code: null, failure_reason: null })]),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /refund…/i })).toBeTruthy());
    fireEvent.click(screen.getByRole('button', { name: /refund…/i }));

    expect(screen.getByText(/accepted rather than done/i)).toBeTruthy();
  });

  it('offers no retry — there is nothing to retry', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment({ status: 'SUCCEEDED', settled: true, failure_code: null, failure_reason: null })]),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByRole('button', { name: /refund…/i })).toBeTruthy());
    expect(screen.queryByRole('button', { name: /try again/i })).toBeNull();
  });
});

describe('an unsettled payment', () => {
  it('cannot be refunded — only settled money can be given back', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment({ status: 'SUCCEEDED', settled: false, failure_code: null, failure_reason: null })]),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getAllByText(/stripe/).length).toBeGreaterThan(0));
    expect(screen.queryByRole('button', { name: /refund…/i })).toBeNull();
  });
});

describe('someone who may only read', () => {
  it('sees the payments and none of the actions', async () => {
    renderAtRoute(
      <PaymentsScreen />,
      clientFor([payment()], {}, { ...SESSION, permissions: ['payments.read'] }),
      { path: '/payments' },
    );

    await waitFor(() => expect(screen.getByTestId('failure')).toBeTruthy());
    expect(screen.queryByRole('button', { name: /try again/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /refund…/i })).toBeNull();
  });
});
