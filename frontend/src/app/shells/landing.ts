import { useNavigate } from '@tanstack/react-router';
import { useEffect } from 'react';

import { useChooseProduct } from '@/app/frame/ProductSwitcher';
import { firstEntry, type NavSection } from '@/app/frame/navigation';
import { useMyProducts } from '@/queries/catalogue';
import { usePublicTenant } from '@/queries/storefront';
import { useSessionStore } from '@/state/session';

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
 * 3. **The screen.** The first entry the rail offers, in the tree's own
 *    order: the console's setup for a platform administrator, Work for a
 *    member. The same place whether they came by the storefront or by a deep
 *    link's form, and the same screen the menu leads with.
 *
 * Only the exact root: a deep link keeps its address. And `replace`, so the
 * empty landing never sits in the history behind the screen it led to.
 */
export function useLanding(atLanding: boolean, sections: readonly NavSection[]): void {
  const navigate = useNavigate();
  const chooseProduct = useChooseProduct();
  const root = useSessionStore((state) => state.root);
  const productCode = useSessionStore((state) => state.productCode);
  const mine = useMyProducts();

  // The bare host is the default organisation's root; which organisation
  // that is, only the public window says — asked wherever the landing is,
  // because a move in either direction turns on it.
  const defaultTenant = usePublicTenant(null, atLanding);

  const first = firstEntry(sections);
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

    // 3. The screen.
    if (first !== undefined) {
      void navigate({ to: first.to, replace: true, search: (previous: Record<string, unknown>) => previous });
    }
  }, [atLanding, known, defaultSlug, root, productCode, chooseProduct, first, navigate]);
}
