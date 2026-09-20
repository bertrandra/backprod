import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import { loadStripe, type Stripe } from '@stripe/stripe-js';
import { useState, type ReactNode } from 'react';

import type { Schemas } from '@/api/client';
import { Button } from '@/ui/Field';
import { Amount, type Money } from '@/ui/Money';
import { notice } from '@/ui/tone';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * Paying, where the `client_secret` was born (ADR-048).
 *
 * A secret is returned once — by `openCheckoutSession`, `startPayment` or
 * `retryPayment` — and never stored, so the only render that can offer a card
 * form is the one that received it. This panel is that form, shared by the
 * three screens that receive one: the storefront after sign-up, the invoice
 * after "Take a payment", the payments list after "Try again". `/checkout/{id}`
 * is the status page a reload lands on and never holds a secret; it stays as
 * it is.
 *
 * **The instrument never touches this platform.** Stripe.js renders its own
 * iframe inside `<PaymentElement>`, the card goes from that iframe to Stripe,
 * and what this code sees is a promise that resolves. Nothing in `frontend/src`
 * gains a `fetch`, a URL, or a hand-copied type of ours — `@stripe/stripe-js`
 * talks to Stripe, not to this platform's API, so the one door to *that* API
 * (`src/api/client.ts`) is untouched and ESLint's ban on `fetch` still holds.
 *
 * **The webhook stays the truth.** `confirmPayment` resolving is the browser's
 * word, and it is not read for a status: `onSettled` is called either way,
 * the caller invalidates or navigates, and the order's status is whatever the
 * server derives once Stripe's webhook has arrived. A decline the Element
 * reports is shown, because Stripe writes those for customers; the *fact* of
 * failure is still the webhook's to record.
 *
 * **One component per provider, chosen by name.** A registry, not a branch:
 * the stub has no page-side part and says so, and a second PSP with its own
 * component is a second entry here and nothing more — the frontend copy of
 * `PaymentProviders`.
 */
export type PaymentProviderClient = NonNullable<Schemas['PaymentProviderClient']>;

export interface PaymentElementPanelProps {
  readonly provider: PaymentProviderClient | null | undefined;
  readonly clientSecret: string | null | undefined;
  readonly amount: Money;
  /** Where a redirect-based method (3-D Secure, a bank page) comes back to. */
  readonly returnUrl: string;
  /** After the customer has been through the form, however it went. */
  readonly onSettled: () => void;
}

export function PaymentElementPanel(props: PaymentElementPanelProps) {
  const { provider, clientSecret } = props;

  if (provider === null || provider === undefined || clientSecret === null || clientSecret === undefined) {
    return null;
  }

  const Panel = PANELS[provider.name];

  return (
    <section data-testid="payment-panel" data-provider={provider.name} className="space-y-3">
      {provider.sandbox && (
        <p data-testid="sandbox-band" className={notice('warning')}>
          <strong>{t("Test payment.")}</strong> {t("This is a sandbox: use a test card, and no money moves.")}</p>
      )}

      {Panel === undefined ? (
        <p data-testid="payment-no-panel" className="text-sm text-muted">
          {tx("The payment was started with {provider}, which has no card form in this page. It will be confirmed when the provider says so.", { provider: <code>{provider.name}</code> })}
        </p>
      ) : (
        <Panel {...props} provider={provider} clientSecret={clientSecret} />
      )}
    </section>
  );
}

type ProviderPanel = (props: PaymentElementPanelProps & { provider: PaymentProviderClient; clientSecret: string }) => ReactNode;

const PANELS: Readonly<Record<string, ProviderPanel>> = {
  stripe: StripePanel,
};

// --- Stripe --------------------------------------------------------------------

/**
 * `loadStripe` injects the script tag for js.stripe.com once per page and
 * must be called once per key; the promise is kept so a re-render does not
 * ask for a second copy.
 */
const stripeByKey = new Map<string, Promise<Stripe | null>>();

function stripeFor(publishableKey: string): Promise<Stripe | null> {
  let loading = stripeByKey.get(publishableKey);

  if (loading === undefined) {
    loading = loadStripe(publishableKey);
    stripeByKey.set(publishableKey, loading);
  }

  return loading;
}

function StripePanel({ provider, clientSecret, amount, returnUrl, onSettled }: PaymentElementPanelProps & { provider: PaymentProviderClient; clientSecret: string }) {
  if (provider.publishable_key === null) {
    return (
      <p data-testid="payment-no-key" className={notice('danger')}>
        {t("This deployment has no publishable key for Stripe, so the card form cannot be shown.")}</p>
    );
  }

  return (
    <Elements stripe={stripeFor(provider.publishable_key)} options={{ clientSecret }}>
      <StripeForm amount={amount} returnUrl={returnUrl} onSettled={onSettled} />
    </Elements>
  );
}

function StripeForm({ amount, returnUrl, onSettled }: { amount: Money; returnUrl: string; onSettled: () => void }) {
  const stripe = useStripe();
  const elements = useElements();
  const [pending, setPending] = useState(false);
  const [declined, setDeclined] = useState<string | null>(null);

  return (
    <form
      data-testid="stripe-form"
      className="space-y-3"
      onSubmit={(event) => {
        event.preventDefault();

        if (stripe === null || elements === null || pending) {
          return;
        }

        setPending(true);
        setDeclined(null);

        // `if_required`: a card stays on this page; a method that needs a
        // redirect (3-D Secure, a bank) leaves and comes back to `returnUrl`,
        // which is the order's own address. Either way the status shown
        // afterwards is the server's, not this promise's.
        void stripe
          .confirmPayment({ elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' })
          .then((result) => {
            if (result.error !== undefined) {
              setDeclined(result.error.message ?? 'The payment could not be completed.');
            }
          })
          .catch(() => setDeclined('The payment could not be completed.'))
          .finally(() => {
            setPending(false);
            onSettled();
          });
      }}
    >
      <PaymentElement />

      {declined !== null && (
        <p data-testid="payment-declined" role="alert" className={notice('danger')}>
          {declined}
        </p>
      )}

      <Button type="submit" pending={pending} disabled={stripe === null || elements === null}>
        {t("Pay")}{' '}<Amount money={amount} />
      </Button>
    </form>
  );
}
