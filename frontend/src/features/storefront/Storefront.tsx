import { useEffect, useState } from 'react';

import { useProductContext } from '@/app/frame/useProductContext';
import { withRoot } from '@/app/root';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useOpenCheckoutSession, type OpenedCheckoutSession } from '@/queries/checkout';
import { usePublicProducts, usePublicTenant, type PublicOffer } from '@/queries/storefront';
import { useSessionStore } from '@/state/session';

import { SignUpForm } from './SignUpForm';
import { StorefrontScreen } from './StorefrontScreen';

/**
 * The page a stranger lands on: an organisation's window, and the door in.
 *
 * **Whose window** is the slug in the address, or the bare host's default
 * organisation (2026-09-17). The offers are what that organisation's
 * products advertise; a stranger sees prices and nothing else.
 *
 * **The door is a request to join, and the purchase follows it.** Sign-up
 * makes a USER membership of the organisation — live or waiting, by its
 * join policy — and since 2026-09-18 a USER may buy (`billing.pay`). So when
 * the membership is live and an offer was chosen, the checkout opens right
 * here on the session the sign-up issued and the card form lives on this
 * page (ADR-048); when the person is waiting, or came in with nothing in
 * hand, the page goes to the root, where the shell opens the catalogue or
 * says "waiting".
 *
 * **Holding the token without declaring the person signed in.** `SignInGate`
 * renders the application the instant the status flips; the page navigates
 * instead, and the session is restored from the refresh cookie on the other
 * side like any other reload.
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
  // The door: open with the offer they came from, open from the footer with
  // none, or shut.
  const [door, setDoor] = useState<{ offer: PublicOffer | null } | null>(null);
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

  const known = tenant.data ?? null;

  if (door === null || known === null) {
    return (
      <StorefrontScreen
        productCode={productCode}
        tenant={slug !== null && tenant.data === null ? 'unknown' : known}
        products={products.data ?? null}
        onChooseProduct={chooseProduct}
        onChoose={(offer) => setDoor({ offer })}
        onSignUp={() => setDoor({ offer: null })}
        onSignIn={onSignIn}
      />
    );
  }

  if (opened !== null && door.offer !== null) {
    const statusPage = withRoot(root, `/checkout/${opened.id}`);

    return (
      <main className="mx-auto max-w-lg space-y-6 p-4 py-10">
        <header className="space-y-1">
          <h1 className="text-2xl font-semibold">Pay</h1>
          <p className="text-sm text-muted">
            {door.offer.name}, for yourself — your account is ready; your seat starts when the
            payment is confirmed.
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

  const offer = door.offer;

  return (
    <SignUpForm
      tenant={known}
      offer={offer}
      productCode={productCode}
      onBack={() => setDoor(null)}
      onSignIn={onSignIn}
      onCreated={(created) => {
        // The platform's choice (2026-09-18): the checkout right here, or
        // the application first — the catalogue, where the same offer is
        // one click away, in the product they chose on this page.
        if (offer !== null && created.membership === 'ACTIVE' && known.after_sign_up === 'CATALOGUE') {
          window.location.assign(`${withRoot(root, '/catalogue')}?product=${encodeURIComponent(productCode ?? '')}`);

          return;
        }

        if (offer === null || created.membership !== 'ACTIVE') {
          // Nothing in hand, or waiting on an administrator: the root says
          // which. A full navigation, so the session is restored from the
          // cookie and the shell boots as it would on any reload.
          window.location.assign(withRoot(root, '/'));

          return;
        }

        // A stranger who just signed up buys a seat of their own (§13.1,
        // 2026-09-18): paid with their card, theirs alone, and beside the
        // organisation's subscription if it holds one. The organisation's
        // purchase is the administrator's and is made from the catalogue.
        void checkout
          .mutateAsync({ offerId: offer.id, seat: true })
          .then((session) => setOpened(session))
          .catch(() => {
            // The account exists and the person is holding a token, so the
            // worst outcome available is landing them at the root — the
            // catalogue, where the same purchase is one click away and the
            // failure is that screen's to explain. Telling them their
            // sign-up failed would send them to create a second account,
            // which the first would then refuse.
            window.location.assign(withRoot(root, '/'));
          });
      }}
    />
  );
}
