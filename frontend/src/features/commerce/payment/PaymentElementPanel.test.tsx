import { fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { renderWith, stubClient } from '@/test-utils';

import { PaymentElementPanel } from './PaymentElementPanel';

/**
 * The card form, with Stripe.js replaced by a fake.
 *
 * What is under test is what *this* code does around Stripe's — that the form
 * is offered only with a secret and a provider that has one, that a sandbox
 * says so, that the return URL is the order's, and above all that whatever
 * `confirmPayment` answers is not read as a status: the caller is told the
 * form has been through, and the server is what says whether money moved.
 */
const confirmPayment = vi.fn();

vi.mock('@stripe/stripe-js', () => ({
  loadStripe: vi.fn(() => Promise.resolve({ confirmPayment })),
}));

vi.mock('@stripe/react-stripe-js', () => ({
  Elements: ({ children }: { children: ReactNode }) => <div data-testid="stripe-elements">{children}</div>,
  PaymentElement: () => <div data-testid="stripe-payment-element" />,
  useStripe: () => ({ confirmPayment }),
  useElements: () => ({}),
}));

const STRIPE = { name: 'stripe', publishable_key: 'pk_test_1', sandbox: true };
const LIVE = { name: 'stripe', publishable_key: 'pk_live_1', sandbox: false };
const STUB = { name: 'stub', publishable_key: null, sandbox: true };
const AMOUNT = { minor_units: 4900, currency: 'EUR' };

function panel(overrides: Partial<Parameters<typeof PaymentElementPanel>[0]> = {}) {
  const onSettled = vi.fn();

  renderWith(
    <PaymentElementPanel
      provider={STRIPE}
      clientSecret="pi_1_secret"
      amount={AMOUNT}
      returnUrl="http://localhost/checkout/order-1"
      onSettled={onSettled}
      {...overrides}
    />,
    stubClient({}),
  );

  return onSettled;
}

afterEach(() => {
  confirmPayment.mockReset();
});

describe('what is offered', () => {
  it('renders nothing without a secret, or without a provider', () => {
    panel({ clientSecret: null });
    expect(screen.queryByTestId('payment-panel')).toBeNull();

    panel({ provider: null });
    expect(screen.queryByTestId('payment-panel')).toBeNull();
  });

  it("offers Stripe's element with the secret, and the amount on the button", async () => {
    panel();

    await waitFor(() => expect(screen.getByTestId('stripe-payment-element')).toBeTruthy());
    expect(screen.getByTestId('payment-panel').getAttribute('data-provider')).toBe('stripe');
    // The server's amount, in minor units, never re-derived here.
    expect(screen.getByRole('button', { name: /Pay/ }).querySelector('[data-minor-units="4900"]')).not.toBeNull();
  });

  it('says so when this is a sandbox, and not when it is live', () => {
    panel();
    expect(screen.getByTestId('sandbox-band')).toBeTruthy();

    panel({ provider: LIVE });
    expect(screen.getAllByTestId('payment-panel')).toHaveLength(2);
    expect(screen.getAllByTestId('sandbox-band')).toHaveLength(1);
  });

  it('has no form for a provider with no page-side part, and says so', () => {
    panel({ provider: STUB });

    expect(screen.getByTestId('payment-no-panel').textContent).toMatch(/stub/);
    expect(screen.queryByTestId('stripe-form')).toBeNull();
  });
});

describe('going through the form', () => {
  it('confirms with the return URL and tells the caller, without reading a status', async () => {
    confirmPayment.mockResolvedValue({ paymentIntent: { status: 'succeeded' } });
    const onSettled = panel();

    await waitFor(() => expect(screen.getByTestId('stripe-form')).toBeTruthy());
    fireEvent.submit(screen.getByTestId('stripe-form'));

    await waitFor(() => expect(onSettled).toHaveBeenCalledTimes(1));
    // The order's own address, so a redirect-based method comes back to it.
    expect(confirmPayment).toHaveBeenCalledWith(
      expect.objectContaining({
        confirmParams: { return_url: 'http://localhost/checkout/order-1' },
        redirect: 'if_required',
      }),
    );
    // Nothing on screen claims success: that is the webhook's word.
    expect(screen.queryByText(/succeeded/i)).toBeNull();
  });

  it("shows Stripe's decline in its own words, and still tells the caller", async () => {
    confirmPayment.mockResolvedValue({ error: { message: 'Your card was declined.' } });
    const onSettled = panel();

    await waitFor(() => expect(screen.getByTestId('stripe-form')).toBeTruthy());
    fireEvent.submit(screen.getByTestId('stripe-form'));

    await waitFor(() => expect(screen.getByTestId('payment-declined').textContent).toBe('Your card was declined.'));
    // The caller re-reads; the *fact* of failure is the webhook's to record.
    expect(onSettled).toHaveBeenCalledTimes(1);
  });

  it('does not submit twice while the first is in flight', async () => {
    let release: (value: unknown) => void = () => undefined;
    confirmPayment.mockReturnValue(new Promise((resolve) => { release = resolve; }));
    panel();

    await waitFor(() => expect(screen.getByTestId('stripe-form')).toBeTruthy());
    fireEvent.submit(screen.getByTestId('stripe-form'));
    fireEvent.submit(screen.getByTestId('stripe-form'));

    expect(confirmPayment).toHaveBeenCalledTimes(1);
    release({});
  });
});
