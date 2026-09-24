import { OfferCard } from '@/features/commerce/OfferCard';
import { useStorefront, type PublicOffer, type PublicProduct, type PublicTenant } from '@/queries/storefront';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';
import { LanguageSelect } from '@/i18n/LanguageSelect';

/**
 * `public.storefront` — the shop window, and the first page anybody sees.
 *
 * **Outside both shells and outside the sign-in gate.** A person here has no
 * session, no product and no permissions, so there is no navigation to draw
 * and nothing a frame could fill in. It renders in place of the sign-in screen
 * for the landing route, which is the same mechanism `SignInGate` already uses
 * to keep a deep link alive across signing in.
 *
 * **Signing in is secondary, and deliberately.** The primary act on this page
 * is choosing something to buy; an account is what that produces, not what it
 * requires. So the sign-in link is one line of text and the offers are the
 * page — the opposite weighting from a product whose front door is a login
 * form.
 *
 * **Nothing here decides what may be shown.** The backend returns only offers
 * marked as advertised and inside their sale window; a filter on this side
 * would be a second opinion on a question already answered, and the two would
 * drift the first time somebody changed one of them.
 *
 * **And nothing here branches on a plan or a product** (non-negotiable #25).
 * Offers arrive in the order the platform ranks them and are rendered in that
 * order. There is no `if (plan.code === 'PRO')` and there must never be one.
 */
export function StorefrontScreen({
  productCode,
  tenant = null,
  products,
  onChooseProduct,
  onChoose,
  onSignUp,
  onSignIn,
}: {
  productCode: string | null;
  /**
   * The organisation whose root this is (2026-09-17): its name in the
   * header, its slug on every window read. Null while unknown or on a bare
   * host with no default; `'unknown'` for a slug nobody has, which is a
   * page of its own rather than an empty shop.
   */
  tenant?: PublicTenant | 'unknown' | null;
  /** The windows there are, or null while that is still being asked. */
  products: readonly PublicProduct[] | null;
  onChooseProduct: (code: string) => void;
  /** What choosing an offer does: since 2026-09-17, open the door with it in hand. */
  onChoose: (offer: PublicOffer) => void;
  /** The door with nothing in hand: ask to join the organisation. */
  onSignUp?: () => void;
  onSignIn: () => void;
}) {
  const slug = tenant === null || tenant === 'unknown' ? null : tenant.slug;
  const storefront = useStorefront(productCode, slug);
  const several = products !== null && products.length > 1;

  if (tenant === 'unknown') {
    return (
      <main className="mx-auto max-w-3xl space-y-8 p-4 py-10">
        <EmptyState
          title={t("No such organisation")}
          description={t("Nothing lives at this address. Check the link you were given, or go to the home page.")}
        />
        <p className="text-center text-sm">
          <a href="/" className="underline underline-offset-2">{t("Home page")}</a>
        </p>
      </main>
    );
  }

  return (
    <main className="mx-auto max-w-3xl space-y-8 p-4 py-10">
      <div className="flex justify-end">
        <LanguageSelect />
      </div>
      <header className="space-y-2 text-center">
        {tenant !== null && (
          <p data-testid="storefront-tenant" className="text-xs font-semibold uppercase tracking-wide text-subtle">
            {tenant.name}
          </p>
        )}
        <h1 className="text-3xl font-semibold">
          {storefront.data?.product?.name ?? t("What we sell")}
        </h1>
        <p className="mx-auto max-w-prose text-sm text-muted">
          {t("Choose a plan to get started. You will create your account as part of the purchase — there is nothing to set up first.")}</p>
      </header>

      {several && (
        // Only when there is a choice: a dropdown with one option is a
        // question with one answer, and the container has already given it.
        <div className="mx-auto max-w-xs">
          <Field id="storefront-product" label={t("Product")}>
            <select
              id="storefront-product"
              data-testid="storefront-product"
              className={inputClass()}
              value={productCode ?? ''}
              onChange={(event) => onChooseProduct(event.target.value)}
            >
              {(productCode === null || productCode === '') && <option value="">{t("Select…")}</option>}
              {products.map((product) => (
                <option key={product.code} value={product.code}>
                  {product.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
      )}

      {products !== null && products.length === 0 ? (
        // No window anywhere: nothing is advertised on this deployment yet.
        // Not an error, and not something the person reading it can fix.
        <EmptyState
          title={t("Nothing on sale yet")}
          description={t("There is nothing to buy here for now. If you already have an account, sign in below.")}
        />
      ) : productCode === null || productCode === '' ? (
        // Several windows and none chosen: the question is the dropdown above.
        // The one case left without a list is a deployment whose products are
        // still being asked for, which the skeleton below covers.
        products === null ? (
          <SkeletonRows rows={3} />
        ) : (
          <EmptyState
            title={t("Choose a product")}
            description={t("Pick one above to see what is on sale for it.")}
          />
        )
      ) : storefront.isPending ? (
        <SkeletonRows rows={3} />
      ) : storefront.error !== null ? (
        <ErrorSurface error={storefront.error} onRetry={() => void storefront.refetch()} />
      ) : storefront.data.offers.length === 0 ? (
        // One message for "no such product", "switched off" and "advertises
        // nothing", because the API deliberately gives one answer for all
        // three — telling them apart here would leak what it withholds.
        <EmptyState
          title={t("Nothing on sale here")}
          description={t("There is nothing to buy for this product yet. If you already have an account, sign in below.")}
        />
      ) : (
        // A grid rather than a stack, because the question on this page is
        // "which of these", and a comparison is made across a row. It collapses
        // to one column on a phone, where a stack is the comparison.
        <ul
          className="grid items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3"
          data-testid="storefront-offers"
        >
          {storefront.data.offers.map((offer) => (
            <OfferCard key={offer.id} offer={offer} onChoose={onChoose} />
          ))}
        </ul>
      )}

      <footer className="border-t border-line pt-4 text-sm">
        <p className="text-muted">
          {t("Already have an account?")}{' '}
          <button
            type="button"
            data-testid="sign-in-link"
            onClick={onSignIn}
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            {t("Sign in")}</button>
          {/* The door with nothing in hand (2026-09-17): only once there is
              an organisation to ask, which a bare host with no default and
              an unknown slug both lack. */}
          {onSignUp !== undefined && tenant !== null && (
            <>
              {t(" · New here? ")}
              <button
                type="button"
                data-testid="sign-up-link"
                onClick={onSignUp}
                className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
              >
                {t("Ask to join")}{' '}{tenant.name}
              </button>
            </>
          )}
        </p>
      </footer>
    </main>
  );
}
