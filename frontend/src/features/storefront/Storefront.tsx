import { useEffect, useState } from 'react';

import { useProductContext } from '@/app/frame/useProductContext';
import { useOpenCheckoutSession } from '@/queries/checkout';
import { usePublicProducts, type PublicOffer } from '@/queries/storefront';
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
  const products = usePublicProducts();
  const [chosen, setChosen] = useState<PublicOffer | null>(null);
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
        products={products.data ?? null}
        onChooseProduct={chooseProduct}
        onChoose={setChosen}
        onSignIn={onSignIn}
      />
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
          .then((session) => window.location.assign(`/checkout/${session.id}`))
          .catch(() => {
            // The account exists and the person is holding a token, so the
            // worst outcome available is landing them in the application at
            // the catalogue — where the same purchase is one click away and
            // the failure is that screen's to explain. Telling them their
            // sign-up failed would send them to create a second account, which
            // the first would then refuse.
            window.location.assign('/catalogue');
          });
      }}
    />
  );
}
