import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect } from 'react';

import { useMyProducts } from '@/queries/catalogue';
import { usePlatformProducts } from '@/queries/staff';
import { useSessionStore } from '@/state/session';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';
import { t } from '@/i18n';

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
 * **Switching resets the query cache, deliberately and completely.** Every
 * cached answer was scoped to the previous product — `X-Product` is on every
 * request — so keeping any of it would mean showing one product's projects,
 * quotes or invoices under another's name. That is the single worst thing this
 * application could do, and it is exactly what a partial invalidation would
 * leave behind: the screen renders from cache while the refetch is in flight,
 * and for those few hundred milliseconds the numbers belong to somebody else's
 * product.
 *
 * Reset, not clear (2026-09-18). `clear()` empties the cache but tells no
 * mounted query, so a screen whose keys do not name the product — the
 * catalogue, the subscription — kept showing the old answer until something
 * else made it ask again; the operator switched product and watched the
 * previous one's offers. `resetQueries()` empties *and* refetches every
 * active query, so the screen goes back to loading and comes back with the
 * product it now names. Being clever with partial keys here is not.
 *
 * A person with one product sees it named and no control: a switcher with one
 * option is a menu that does nothing.
 */
export function ProductSwitcher({ platform = false }: { platform?: boolean }) {
  const productCode = useSessionStore((state) => state.productCode);
  const seed = useSessionStore((state) => state.chooseProduct);
  const chooseProduct = useChooseProduct();

  const mine = useMyProducts(!platform);
  const platformProducts = usePlatformProducts(platform);

  const known: readonly ProductOption[] = platform
    ? (platformProducts.data ?? []).map((product) => ({
        code: product.code,
        name: product.active ? product.name : t("{name} (retired)", { name: product.name }),
        retired: !product.active,
        // The console administers a product where it is, never leaves for it.
        appUrl: null,
      }))
    : (mine.data?.products ?? []).map((product) => ({ code: product.code, name: product.name, retired: false, appUrl: product.app_url ?? null }));

  // The person's own default, where the server named one they still hold;
  // the shell opens there rather than on whichever product sorts first.
  const preferred = platform ? null : (mine.data?.default ?? null);

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

    const first =
      known.find((product) => product.code === preferred) ??
      known.find((product) => !product.retired) ??
      known[0];

    if (first !== undefined) {
      seed(first.code);
    }
  }, [platform, productCode, known, preferred, seed]);

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
      <span className="sr-only">{t("Product")}</span>
      <select
        data-testid="product-switcher"
        data-product={productCode ?? ''}
        value={productCode ?? ''}
        onChange={(event) => {
          const chosen = known.find((product) => product.code === event.target.value);

          // A product deployed beside the platform (ADR-051 §3) is left
          // for, not switched to: its own page resumes the session from
          // the cookie. `?product=` and nothing else travels.
          if (chosen?.appUrl != null) {
            leaveFor(chosen.appUrl, chosen.code);

            return;
          }

          chooseProduct(event.target.value);
        }}
        className={cn(
          touchTargetClass,
          // No inverse fill (2026-09-18): a black block in the bar read as a
          // badge, not as a control, and shouted over the organisation's name.
          'rounded-control border border-line bg-surface px-2 py-1 text-xs font-semibold text-ink shadow-raise focus-visible:outline-2 focus-visible:outline-offset-2',
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
  /** Where the product lives when it is not this shell (ADR-051). */
  readonly appUrl: string | null;
}

/**
 * The way to a product deployed beside the platform: a full navigation to
 * its address, with the product code. No token: the product's page asks
 * `/auth/refresh` and the cookie answers, because the two are one site.
 *
 * **The language does not travel** (2026-09-23). It used to, as `?lang=`,
 * so the product's page opened in the language the shell was reading in.
 * That was the same parameter the shell let override a person's own
 * setting, and it is gone for the same reason: the language is a fact
 * about the person, not about the link. The product asks the platform who
 * is arriving — `/me/context` carries `user.locale` — and reads it there,
 * which is right even when somebody types the product's address directly
 * and no link is involved.
 *
 * `project` is added when the person was on one (2026-09-22). A product
 * beside the platform stores its documents *in* the platform, so the id
 * means the same thing on both sides, and a door that dropped it would
 * land somebody in whichever project the product last remembered — a
 * worse answer than none, because it looks like an answer. It is an
 * identifier, not a credential: the product still asks the platform for
 * the project, and the platform still refuses it to anyone else.
 */
export function leaveFor(appUrl: string, code: string, projectId: string | null = null): void {
  window.location.assign(addressFor(appUrl, code, projectId));
}

/**
 * The same address, as a string, for a real `<a href>`.
 *
 * A list of projects wants links, not buttons that navigate: a link can be
 * opened in a new tab, copied, and read in the status bar before it is
 * followed. `leaveFor` is what a control uses when there is no anchor to
 * hang the address on — the switcher's `<select>`, a button on a screen.
 */
export function addressFor(appUrl: string, code: string, projectId: string | null = null): string {
  const address = new URL(appUrl);
  address.searchParams.set('product', code);

  if (projectId !== null) {
    address.searchParams.set('project', projectId);
  }

  return address.toString();
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
      // Everything in the cache was answered for the previous product:
      // dropped, and whatever is on screen asked again for this one.
      void queryClient.resetQueries();
    },
    [chooseProduct, queryClient],
  );
}
