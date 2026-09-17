import { useEffect, useState } from 'react';

import { useProductContext } from '@/app/frame/useProductContext';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useOpenCheckoutSession, type OpenedCheckoutSession } from '@/queries/checkout';
import { withRoot } from '@/app/root';
import { usePublicProducts, usePublicTenant, type PublicOffer } from '@/queries/storefront';
import { useSessionStore } from '@/state/session';

import { SignUpForm } from './SignUpForm';
import { StorefrontScreen } from './StorefrontScreen';

/**
 * The whole public path, from a stranger to a paid subscription.
 *
 * Three steps and one component, because they are one act: choosing, creating
 * the account that can own what was chosen, and paying for it. Splitting them
 * across routes would mean carrying the chosen offer through a URL and losing
 * it on every refresh, for no gain — nobody deep-links into step two.
 *
 * **The checkout is not a fourth step.** Once the account exists the purchase
 * is the same authenticated checkout the catalogue screen opens:
 * `openCheckoutSession`, then the ordinary `/checkout/:id` screen with its
 * order, its invoice and its payment. Nothing about buying from the storefront
 * is a separate flow, which is what keeps ADR-034 true — a session is an
 * order, wherever it started.
 *
 * **The order of the two calls is load-bearing.** Sign-up leaves the token in
 * the store *without* flipping the status, because `SignInGate` renders the
 * application the moment it flips — which would unmount this component
 * mid-purchase and lose the checkout it is making. So the checkout happens
 * while this page is still on screen, and the status changes by way of the
 * navigation that follows, where the session is restored from the refresh
 * cookie like any other reload. An E2E run is what found this; it is invisible
 * to a unit test, where nothing is gating anything.
 */
export function Storefront({ onSignIn }: { onSignIn: () => void }) {
  const { productCode } = useProductContext();
  const chooseProduct = useSessionStore((state) => state.chooseProduct);
  const root = useSessionStore((state) => state.root);
  const slug = useSessionStore((state) => state.tenantSlug);
  const enterRoot = useSessionStore((state) => state.enterRoot);
  // Whose window this is (2026-09-17): the organisation at the slug, or the
  // bare host's default. On the bare host the answer also teaches the store
  // the default tenant's slug, which every request then names.
  const tenant = usePublicTenant(slug);

  useEffect(() => {
    if (slug === null && tenant.data !== undefined && tenant.data !== null) {
      enterRoot(root, tenant.data.slug);
    }
  }, [slug, tenant.data, root, enterRoot]);

  const products = usePublicProducts(tenant.data?.slug ?? null);
  const [chosen, setChosen] = useState<PublicOffer | null>(null);
  // The session just opened, held for exactly as long as the render that
  // received its `client_secret` — the card form lives here, and the hop to
  // /checkout/{id} afterwards is a full navigation that restores the session
  // and, by design, cannot carry the secret (ADR-034, ADR-048).
  const [opened, setOpened] = useState<OpenedCheckoutSession | null>(null);
  const checkout = useOpenCheckoutSession();

  // The windows a stranger may choose between (ADR-047). With exactly one,
  // it is chosen for them — a dropdown with one option is a question with one
  // answer — unless the address already named it. With several, the choice is
  // theirs and the screen asks; `?product=` still seeds it, so a link to one
  // product's page keeps working.
  const windows = products.data;
  const only = windows !== undefined && windows.length === 1 ? (windows[0]?.code ?? null) : null;

  useEffect(() => {
    // With one window, `only` is that window — so "the store names something
    // else" is the one case left to correct.
    if (only !== null && productCode !== only) {
      chooseProduct(only);
    }
  }, [only, productCode, chooseProduct]);

  if (chosen === null || productCode === null) {
    return (
      <StorefrontScreen
        productCode={productCode}
        tenant={slug !== null && tenant.data === null ? 'unknown' : (tenant.data ?? null)}
        products={products.data ?? null}
        onChooseProduct={chooseProduct}
        onChoose={setChosen}
        onSignIn={onSignIn}
      />
    );
  }

  if (opened !== null) {
    const statusPage = withRoot(root, `/checkout/${opened.id}`);

    return (
      <main className="mx-auto max-w-lg space-y-6 p-4 py-10">
        <header className="space-y-1">
          <h1 className="text-2xl font-semibold">Pay</h1>
          <p className="text-sm text-muted">
            {chosen.name} — your account is ready; the subscription starts when the payment is
            confirmed.
          </p>
        </header>

        <PaymentElementPanel
          provider={opened.payment_provider}
          clientSecret={opened.client_secret}
          amount={opened.gross}
          returnUrl={new URL(statusPage, window.location.origin).toString()}
          // Whatever the form said, the status page says what the server knows.
          onSettled={() => window.location.assign(statusPage)}
        />

        {/* Always there: a free offer has nothing to pay, a provider with no
            card form confirms on its own, and somebody who changes their mind
            still has an order to come back to (ADR-034). */}
        <p className="text-sm text-muted">
          <a href={statusPage} data-testid="continue-to-order" className="underline underline-offset-2">
            Continue to your order
          </a>
          {opened.client_secret !== null && opened.client_secret !== undefined && ' — it can be paid from there later.'}
        </p>
      </main>
    );
  }

  return (
    <SignUpForm
      offer={chosen}
      productCode={productCode}
      onBack={() => setChosen(null)}
      onSignIn={onSignIn}
      onCreated={() => {
        void checkout
          .mutateAsync(chosen.id)
          .then((session) => setOpened(session))
          .catch(() => {
            // The account exists and the person is holding a token, so the
            // worst outcome available is landing them in the application at
            // the catalogue — where the same purchase is one click away and
            // the failure is that screen's to explain. Telling them their
            // sign-up failed would send them to create a second account, which
            // the first would then refuse.
            window.location.assign(withRoot(root, '/catalogue'));
          });
      }}
    />
  );
}
