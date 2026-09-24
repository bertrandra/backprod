import { contentFrom, isRetired } from '@/features/showcase/blocks/fromApi';
import { BAND_META } from '@/features/showcase/blocks/meta';
import { Showcase } from '@/features/showcase/Showcase';
import { usePublicShowcase } from '@/queries/showcase';
import { useStorefront, type PublicOffer, type PublicProduct, type PublicTenant } from '@/queries/storefront';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { buttonClass, Field, inputClass } from '@/ui/Field';
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
  const story = usePublicShowcase(productCode);
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
    <main className="min-h-dvh">
      {/* The page's own chrome, in a measure — whose organisation this is,
          which language it is read in, which window. The story below runs
          full width: it is a shop window, not a document. */}
      <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4 md:px-8">
        {tenant !== null ? (
          <p data-testid="storefront-tenant" className="text-xs font-semibold uppercase tracking-[0.14em] text-subtle">
            {tenant.name}
          </p>
        ) : (
          <span />
        )}
        <LanguageSelect />
      </div>

      {several && (
        // Only when there is a choice: a dropdown with one option is a
        // question with one answer, and the container has already given it.
        <div className="mx-auto w-full max-w-6xl px-4 pb-2 md:px-8">
          <div className="max-w-xs">
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
        </div>
      )}

      {products !== null && products.length === 0 ? (
        // No window anywhere: nothing is advertised on this deployment yet.
        // Not an error, and not something the person reading it can fix.
        <div className="mx-auto w-full max-w-3xl px-4 py-10">
          <EmptyState
            title={t("Nothing on sale yet")}
            description={t("There is nothing to buy here for now. If you already have an account, sign in below.")}
          />
        </div>
      ) : productCode === null || productCode === '' ? (
        // Several windows and none chosen: the question is the dropdown above.
        // The one case left without a list is a deployment whose products are
        // still being asked for, which the skeleton below covers.
        <div className="mx-auto w-full max-w-3xl px-4 py-10">
          {products === null ? (
            <SkeletonRows rows={3} />
          ) : (
            <EmptyState
              title={t("Choose a product")}
              description={t("Pick one above to see what is on sale for it.")}
            />
          )}
        </div>
      ) : storefront.error !== null ? (
        <div className="mx-auto w-full max-w-3xl px-4 py-10">
          <ErrorSurface error={storefront.error} onRetry={() => void storefront.refetch()} />
        </div>
      ) : (
        // **The same page a member reads** (docs/home-showcase-spec.md §2).
        // What differs is the call to action — choosing an offer here opens
        // the door rather than a checkout — never the story. A second
        // rendering of it for strangers would be the one nobody proofread.
        <Showcase
          productName={storefront.data?.product?.name ?? t("What we sell")}
          content={contentFrom(story.data)}
          offers={storefront.data?.offers ?? []}
          offersLoading={storefront.isPending}
          onChooseOffer={onChoose}
          retired={isRetired(story.data)}
          action={
            <a href={`#${BAND_META.PRICING.anchor}`} data-testid="see-the-offers" className={buttonClass()}>
              {t("See the offers")}</a>
          }
        />
      )}
      <footer className="border-t border-line bg-canvas px-4 py-8 text-sm md:px-8">
        <p className="mx-auto max-w-6xl text-muted">
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
