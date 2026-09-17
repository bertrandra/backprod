import { useEffect, useState } from 'react';

import { useProductContext } from '@/app/frame/useProductContext';
import { withRoot } from '@/app/root';
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
 * **The door is a request to join, not a purchase.** Sign-up makes a USER
 * membership of the organisation — live or waiting, by its join policy —
 * and a USER cannot check out (docs/tenant-roots.md §2.4). So there is no
 * pay step here any more: once the account exists the page goes to the
 * root, where the shell opens the member's catalogue or says "waiting". The
 * offer they chose is carried onto the form so they know what to ask for.
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

  return (
    <SignUpForm
      tenant={known}
      offer={door.offer}
      productCode={productCode}
      onBack={() => setDoor(null)}
      onSignIn={onSignIn}
      onCreated={() => {
        // In, or waiting: the root says which. A full navigation, so the
        // session is restored from the cookie and the shell boots as it
        // would on any reload.
        window.location.assign(withRoot(root, '/'));
      }}
    />
  );
}
