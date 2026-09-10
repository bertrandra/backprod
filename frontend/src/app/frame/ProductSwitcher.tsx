import { useQueryClient } from '@tanstack/react-query';

import { useProducts } from '@/queries/catalogue';
import { useSessionStore } from '@/state/session';

/**
 * Which product this browser is acting in — region A's first duty.
 *
 * U1 deferred this with a reason: a switcher needs `listProducts`, which the
 * coverage map assigns to `commerce.catalogue`. That operation arrives in U5, so
 * the switcher does too, and `useProducts` is used rather than left waiting.
 *
 * **Switching clears the query cache, deliberately and completely.** Every cached
 * answer was scoped to the previous product — `X-Product` is on every request —
 * so keeping any of it would mean showing one product's projects, quotes or
 * invoices under another's name. That is the single worst thing this application
 * could do, and it is exactly what a partial invalidation would leave behind: the
 * screen renders from cache while the refetch is in flight, and for those few
 * hundred milliseconds the numbers belong to somebody else's product.
 *
 * Clearing is cheap and obviously correct. Being clever here is not.
 *
 * A person with one product sees it named and no control: a switcher with one
 * option is a menu that does nothing.
 */
export function ProductSwitcher() {
  const queryClient = useQueryClient();
  const productCode = useSessionStore((state) => state.productCode);
  const chooseProduct = useSessionStore((state) => state.chooseProduct);

  const products = useProducts();

  const known = products.data ?? [];
  const current = known.find((product) => product.code === productCode) ?? null;

  // Before the list arrives — or if it fails — the code is still the truth about
  // what every request is carrying, so it is shown rather than a spinner.
  if (known.length <= 1) {
    return (
      <span
        data-testid="active-product"
        data-product={productCode ?? ''}
        className="rounded bg-neutral-900 px-2 py-1 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900"
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
        onChange={(event) => {
          const next = event.target.value;

          if (next === productCode) {
            return;
          }

          chooseProduct(next);
          // Everything in the cache was answered for the previous product.
          queryClient.clear();
        }}
        className="rounded bg-neutral-900 px-2 py-1 text-xs font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-neutral-100 dark:text-neutral-900"
      >
        {known.map((product) => (
          <option key={product.id} value={product.code}>
            {product.name}
          </option>
        ))}
      </select>
    </label>
  );
}
