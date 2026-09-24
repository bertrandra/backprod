import { useEffect } from 'react';

import { configuredProduct, rememberedProduct, useSessionStore } from '@/state/session';

/**
 * How U1 learns which product it is in.
 *
 * Region A owns "which product", but a *switcher* needs the list of products,
 * and `listProducts` belongs to `commerce.catalogue` — U5. Rather than borrow
 * it early and leave the coverage map disagreeing with the code, U1 takes the
 * product from the URL or from configuration and shows an honest empty state
 * when it has neither. The switcher arrives with the list that feeds it.
 *
 * `?product=` is read once on mount and then lives in the store, so it does not
 * have to be carried through every subsequent link — and is remembered in
 * `localStorage` so it survives a reload, because the router drops the parameter
 * the moment somebody navigates and a reload would otherwise land on an empty
 * state with nothing wrong.
 *
 * The order is deliberate: an explicit `?product=` in the URL wins over what the
 * browser remembers, which wins over the configured default. A link is somebody
 * saying which product they mean *now*.
 */
export function useProductContext(): { productCode: string | null } {
  const productCode = useSessionStore((s) => s.productCode);
  const chooseProduct = useSessionStore((s) => s.chooseProduct);

  useEffect(() => {
    if (productCode !== null) {
      return;
    }

    const fromUrl = new URLSearchParams(window.location.search).get('product');
    const next = fromUrl ?? rememberedProduct() ?? configuredProduct();

    if (next !== null && next !== '') {
      chooseProduct(next);
    }
  }, [productCode, chooseProduct]);

  return { productCode };
}
