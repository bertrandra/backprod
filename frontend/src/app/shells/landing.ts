import { useNavigate } from '@tanstack/react-router';
import { useEffect } from 'react';

import { useChooseProduct } from '@/app/frame/ProductSwitcher';
import { firstEntry, type NavSection } from '@/app/frame/navigation';
import { useMyProducts } from '@/queries/catalogue';
import { usePublicTenant } from '@/queries/storefront';
import { hasLanded, markLanded, useSessionStore } from '@/state/session';

/**
 * Where the landing address goes once somebody has signed in (2026-09-18).
 *
 * `/` inside the shell is nobody's screen; it is where the storefront's
 * sign-in link, the sign-up and a sign-out all leave a person. Three things
 * are settled here, in order, so that the screen they reach is *theirs*:
 *
 * 1. **The root.** A member belongs at their organisation's root — `/acme/`
 *    — and the default organisation's members at the bare host. Somebody who
 *    signed in at the bare host but belongs to Acme is moved there, by a full
 *    navigation, because the router's basepath is fixed when the page boots.
 *    Where they already stand under a root that is theirs, nothing moves.
 * 2. **The product.** The person's own default — the product they signed up
 *    for, or chose on their profile — unless the address names one, which is
 *    somebody saying which they mean now.
 * 3. **Their first screen**, once. The first entry of their own menu: their
 *    work for a member, the console's setup for platform staff — on
 *    arrival only, so a deliberate return to `/` stays on the story.
 *
 * Step 3 was removed earlier on 2026-09-24 and is back the same day, which
 * is worth recording rather than quietly reverting. It was removed so that
 * `/` — the product's story, built that morning — would be an address
 * somebody could actually reach; before it, a member was ejected from it
 * before the shell had rendered and the page existed for strangers only.
 *
 * That reasoning was right about the page and wrong about the landing. The
 * operator signed in and got a shop window for a product they had already
 * bought, with their projects two clicks away; a platform administrator got
 * a tenant's front page rather than the console they signed in to run. **A
 * page being reachable and a page being where signing in puts you are
 * different questions**, and the first does not need the second.
 *
 * But it does need a door, and there was none: `/` is in no menu. So two
 * things make the page reachable — the redirect fires **once per browsing
 * session**, so any later arrival at `/` stays there, whether it came from
 * a link or from the address bar; and region A carries an *About* link to
 * it (the drawer on a phone).
 *
 * So the story keeps its address and signing in goes back to meaning "take
 * me to my work". What a person sees at `/` is unchanged — this only stops
 * them being *left* there.
 *
 * Only the exact root: a deep link keeps its address.
 */
export function useLanding(atLanding: boolean, sections: readonly NavSection[] = []): void {
  const navigate = useNavigate();
  const chooseProduct = useChooseProduct();
  const root = useSessionStore((state) => state.root);
  const productCode = useSessionStore((state) => state.productCode);
  const mine = useMyProducts();

  // The bare host is the default organisation's root; which organisation
  // that is, only the public window says — asked wherever the landing is,
  // because a move in either direction turns on it.
  const defaultTenant = usePublicTenant(null, atLanding);

  const known = mine.data;
  const defaultSlug = defaultTenant.data === undefined ? undefined : (defaultTenant.data?.slug ?? null);

  useEffect(() => {
    if (!atLanding || known === undefined) {
      return;
    }

    const memberships = known.memberships.map((membership) => membership.tenant);
    const preferred = known.default;
    const products = known.products;

    // 1. The root. Decided only once the organisations are known, and the
    // default organisation too.
    if (memberships.length > 0) {
      if (defaultSlug === undefined) {
        return;
      }

      const here = root.replace(/^\//, '') || defaultSlug || null;
      const target = memberships[0];

      if ((here === null || !memberships.includes(here)) && target !== undefined) {
        // The default organisation's members belong at the bare host.
        window.location.assign(target === defaultSlug ? '/' : `/${target}/`);

        return;
      }
    }

    // 2. The product: theirs, unless the address says otherwise.
    const named = new URLSearchParams(window.location.search).get('product');

    if (
      named === null &&
      preferred !== null &&
      preferred !== productCode &&
      products.some((product) => product.code === preferred)
    ) {
      chooseProduct(preferred);

      return;
    }

    // The landing used to leave here for a product deployed beside the
    // platform (ADR-051 §3), on the reasoning that its address *is* its
    // landing. Removed on 2026-09-24: it meant somebody whose default
    // product lives elsewhere could never reach a platform screen for it,
    // because `/` threw them out before the shell rendered — their
    // subscription, their invoices, their members and their projects are
    // all here. The product's own door is a door somebody opens: the card
    // above the project list, or a project's name.
    //
    // It is also why this went unnoticed for three days: `GET /products`
    // never carried `app_url` until 2026-09-23, so nothing ever left.

    // 3. **Their first screen**, in their menu's own order — Work before
    // Commerce for a member, the platform's Setup before its Customers for
    // staff. So a member lands on their projects and an administrator lands
    // in the console, each on the screen their own permissions put first,
    // with no list of names here that could disagree with the menu beside
    // it.
    //
    // Waited for, not guessed: an empty `sections` is the menu not having
    // arrived, and moving then would send everybody to the same screen —
    // whichever one happens to survive an empty permission set. Nothing
    // moves until there is something to move to.
    const first = firstEntry(sections);

    if (first === undefined) {
      return;
    }

    // **Once**, on arrival — and this is what makes the page reachable at
    // the same time as the landing means "my work".
    //
    // The redirect was removed earlier on 2026-09-24 because it fired on
    // *every* visit to `/`, so somebody who went to read the product's
    // story was thrown off it again before it rendered. That is a real
    // defect, and restoring the redirect unchanged would restore it.
    //
    // Landing is arriving, not visiting. The flag lives in `sessionStorage`
    // and not in a ref, because a full load of `/` — typing the address, or
    // the drawer's link, which is a real navigation — is a fresh mount and
    // a ref would have called it an arrival. It survives the reload and
    // dies with the tab; `forget()` clears it, so signing out and back in
    // lands again.
    if (hasLanded()) {
      return;
    }

    markLanded();
    void navigate({ to: first.to, replace: true });
  }, [atLanding, known, defaultSlug, root, productCode, chooseProduct, sections, navigate]);
}
