import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect } from 'react';

import { useProducts } from '@/queries/catalogue';
import { usePlatformProducts } from '@/queries/staff';
import { useSessionStore } from '@/state/session';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

/**
 * Which product this browser is acting in — region A's first duty.
 *
 * U1 deferred this with a reason: a switcher needs `listProducts`, which the
 * coverage map assigns to `commerce.catalogue`. That operation arrives in U5, so
 * the switcher does too, and `useProducts` is used rather than left waiting.
 *
 * **Two lists, one store.** In the application the options are the products
 * this person belongs to — `listProducts`, answered from their memberships. On
 * the console (`platform`) they are every product the platform hosts, retired
 * ones included, from `listPlatformProducts` — because a platform role grants
 * no membership (non-negotiable #22), so a platform administrator asked
 * "which products may you reach?" would be answered about their own tenant
 * or not at all (ADR-042). Both write the same `productCode`, so the product
 * chosen here is the one every screen reads and every request carries
 * (ADR-047): the console has no second product of its own in the URL.
 *
 * **Switching clears the query cache, deliberately and completely.** Every
 * cached answer was scoped to the previous product — `X-Product` is on every
 * request — so keeping any of it would mean showing one product's projects,
 * quotes or invoices under another's name. That is the single worst thing this
 * application could do, and it is exactly what a partial invalidation would
 * leave behind: the screen renders from cache while the refetch is in flight,
 * and for those few hundred milliseconds the numbers belong to somebody else's
 * product.
 *
 * Clearing is cheap and obviously correct. Being clever here is not.
 *
 * A person with one product sees it named and no control: a switcher with one
 * option is a menu that does nothing.
 */
export function ProductSwitcher({ platform = false }: { platform?: boolean }) {
  const productCode = useSessionStore((state) => state.productCode);
  const seed = useSessionStore((state) => state.chooseProduct);
  const chooseProduct = useChooseProduct();

  const mine = useProducts(!platform);
  const platformProducts = usePlatformProducts(platform);

  const known: readonly ProductOption[] = platform
    ? (platformProducts.data ?? []).map((product) => ({
        code: product.code,
        name: product.active ? product.name : `${product.name} (retired)`,
        retired: !product.active,
      }))
    : (mine.data ?? []).map((product) => ({ code: product.code, name: product.name, retired: false }));

  const current = known.find((product) => product.code === productCode) ?? null;

  // Nothing chosen yet — the console on first arrival, or the application
  // after a sign-in at the landing address, where signing out had forgotten
  // the product on purpose — so the first product this person may act in is
  // chosen for them, and shown in the bar the moment it is. This is not
  // ADR-013's forbidden default: that is the *server* guessing a product a
  // request did not name; here the client chooses among the products the
  // server said this person has, and every request then names it. Until
  // 2026-09-17 the application side refused to choose, and the operator
  // signed back in to a screen with nothing on it: no product, so no `/me`,
  // so no menu. The store alone, not `useChooseProduct`: nothing in the
  // cache belongs to a previous product when there was none.
  useEffect(() => {
    if (productCode !== null || known.length === 0) {
      return;
    }

    const first = known.find((product) => !product.retired) ?? known[0];

    if (first !== undefined) {
      seed(first.code);
    }
  }, [platform, productCode, known, seed]);

  // Before the list arrives — or if it fails — the code is still the truth about
  // what every request is carrying, so it is shown rather than a spinner.
  if (known.length <= 1) {
    return (
      <span
        data-testid="active-product"
        data-product={productCode ?? ''}
        className="inline-flex items-center gap-1.5 rounded-full border border-line bg-well px-2.5 py-1 text-xs font-medium text-ink"
      >
        {current?.name ?? productCode ?? '—'}
      </span>
    );
  }

  return (
    <label className="flex items-center gap-1">
      <span className="sr-only">Product</span>
      <select
        data-testid="product-switcher"
        data-product={productCode ?? ''}
        value={productCode ?? ''}
        onChange={(event) => chooseProduct(event.target.value)}
        className={cn(
          touchTargetClass,
          'rounded-control bg-inverse px-2 py-1 text-xs font-semibold text-on-inverse focus-visible:outline-2 focus-visible:outline-offset-2',
        )}
      >
        {known.map((product) => (
          <option key={product.code} value={product.code} data-retired={product.retired ? 'true' : undefined}>
            {product.name}
          </option>
        ))}
      </select>
    </label>
  );
}

interface ProductOption {
  readonly code: string;
  readonly name: string;
  readonly retired: boolean;
}

/**
 * Choose a product the way the switcher does: write the store, and drop
 * everything the previous product answered. Exported so a screen that hands
 * somebody to a product — the products list's "Catalogue" button — does the
 * same two things rather than the first one only.
 */
export function useChooseProduct(): (code: string) => void {
  const queryClient = useQueryClient();
  const chooseProduct = useSessionStore((state) => state.chooseProduct);

  return useCallback(
    (code: string) => {
      if (code === useSessionStore.getState().productCode) {
        return;
      }

      chooseProduct(code);
      // Everything in the cache was answered for the previous product.
      queryClient.clear();
    },
    [chooseProduct, queryClient],
  );
}
