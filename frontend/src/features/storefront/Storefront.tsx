import { useState } from 'react';

import { useProductContext } from '@/app/frame/useProductContext';
import { useOpenCheckoutSession } from '@/queries/checkout';
import type { PublicOffer } from '@/queries/storefront';
import { ErrorSurface } from '@/ui/ErrorSurface';

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
 * **The checkout is not a fourth step here.** Once the account exists the
 * person is signed in, and the purchase is the same authenticated checkout
 * the catalogue screen opens: `openCheckoutSession`, then the ordinary
 * `/checkout/:id` screen with its order, its invoice and its payment. Nothing
 * about buying from the storefront is a separate flow with rules of its own,
 * which is what keeps ADR-034 true — a session is an order, wherever it
 * started.
 *
 * The hop into the application is a real navigation rather than a router
 * push, because this renders *outside* the router: the gate has not let the
 * shells mount yet. One page load, and the session survives it through the
 * refresh cookie.
 */
export function Storefront({ onSignIn }: { onSignIn: () => void }) {
  const { productCode } = useProductContext();
  const [chosen, setChosen] = useState<PublicOffer | null>(null);
  const checkout = useOpenCheckoutSession();

  if (chosen === null || productCode === null) {
    return (
      <StorefrontScreen productCode={productCode} onChoose={setChosen} onSignIn={onSignIn} />
    );
  }

  return (
    <>
      {/* The account exists by now, so a checkout that fails is not a sign-up
          that failed — said separately, above a form that would otherwise
          look like the thing that went wrong. */}
      {checkout.error !== null && (
        <div className="mx-auto max-w-sm p-4 pt-10">
          <ErrorSurface error={checkout.error} />
          <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
            Your account was created. Sign in and buy from the catalogue.
          </p>
        </div>
      )}

      <SignUpForm
        offer={chosen}
        productCode={productCode}
        onBack={() => setChosen(null)}
        onSignIn={onSignIn}
        onCreated={() => {
          checkout.mutate(chosen.id, {
            onSuccess: (session) => {
              window.location.assign(`/checkout/${session.id}`);
            },
          });
        }}
      />
    </>
  );
}
