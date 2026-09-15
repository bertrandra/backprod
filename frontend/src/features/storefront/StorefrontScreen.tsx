import { useStorefront, type PublicOffer, type PublicProduct } from '@/queries/storefront';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

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
  products,
  onChooseProduct,
  onChoose,
  onSignIn,
}: {
  productCode: string | null;
  /** The windows there are, or null while that is still being asked. */
  products: readonly PublicProduct[] | null;
  onChooseProduct: (code: string) => void;
  /** What choosing an offer does. Lot B makes it sign-up-then-buy. */
  onChoose: (offer: PublicOffer) => void;
  onSignIn: () => void;
}) {
  const storefront = useStorefront(productCode);
  const several = products !== null && products.length > 1;

  return (
    <main className="mx-auto max-w-3xl space-y-8 p-4 py-10">
      <header className="space-y-2 text-center">
        <h1 className="text-3xl font-semibold">
          {storefront.data?.product?.name ?? 'What we sell'}
        </h1>
        <p className="mx-auto max-w-prose text-sm text-muted">
          Choose a plan to get started. You will create your account as part of the purchase —
          there is nothing to set up first.
        </p>
      </header>

      {several && (
        // Only when there is a choice: a dropdown with one option is a
        // question with one answer, and the container has already given it.
        <div className="mx-auto max-w-xs">
          <Field id="storefront-product" label="Product">
            <select
              id="storefront-product"
              data-testid="storefront-product"
              className={inputClass()}
              value={productCode ?? ''}
              onChange={(event) => onChooseProduct(event.target.value)}
            >
              {(productCode === null || productCode === '') && <option value="">Select…</option>}
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
          title="Nothing on sale yet"
          description="There is nothing to buy here for now. If you already have an account, sign in below."
        />
      ) : productCode === null || productCode === '' ? (
        // Several windows and none chosen: the question is the dropdown above.
        // The one case left without a list is a deployment whose products are
        // still being asked for, which the skeleton below covers.
        products === null ? (
          <SkeletonRows rows={3} />
        ) : (
          <EmptyState
            title="Choose a product"
            description="Pick one above to see what is on sale for it."
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
          title="Nothing on sale here"
          description="There is nothing to buy for this product yet. If you already have an account, sign in below."
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
            <OfferCard key={offer.id} offer={offer} onChoose={() => onChoose(offer)} />
          ))}
        </ul>
      )}

      <footer className="border-t border-line pt-4 text-sm">
        <p className="text-muted">
          Already have an account?{' '}
          <button
            type="button"
            data-testid="sign-in-link"
            onClick={onSignIn}
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            Sign in
          </button>
        </p>
      </footer>
    </main>
  );
}

/**
 * One offer, as a price with a reason to believe it.
 *
 * The old card was a row: a name, a plan, a price and a button, all at the same
 * weight, repeated down the page in identical grey. It made every offer look
 * like every other one and left the visitor with nothing to compare but two
 * numbers.
 *
 * So the price is the card's largest element — it is what a person came for —
 * and underneath it is **what the offer actually grants**, which the version has
 * carried all along and this page never showed. A quota reads as its limit and
 * its unit; `unlimited` is its own word, because "unlimited" and a limit of zero
 * are opposite facts and a bare number cannot tell them apart.
 *
 * **Nothing here is ordered or emphasised by plan** (non-negotiable #25). There
 * is no "most popular" badge, because deciding which plan deserved one would be
 * a branch on a plan code. The platform's rank is the order, and the order is
 * the recommendation.
 */
function OfferCard({ offer, onChoose }: { offer: PublicOffer; onChoose: () => void }) {
  const version = offer.version;
  const grants = version?.grants ?? [];

  return (
    <li
      data-offer={offer.id}
      className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-raise transition-shadow hover:shadow-float"
    >
      <div className="space-y-1">
        <p className="text-lg font-semibold">{offer.name}</p>
        <p className="text-xs text-muted">{offer.plan.name}</p>
      </div>

      {version === null ? (
        // Typed nullable in the contract, so said plainly rather than
        // rendered as a zero — and a zero is a legitimate price.
        <p data-testid="no-price" className="text-sm text-subtle">
          Price on request
        </p>
      ) : (
        <>
          <p className="flex items-baseline gap-1.5">
            <Amount
              money={version.price}
              className="text-3xl font-semibold [font-variant-numeric:proportional-nums]"
            />
            <span className="text-xs text-muted">
              {billingPeriod(version.billing_period)}
            </span>
          </p>

          {grants.length > 0 && (
            <ul className="space-y-1.5 text-sm" data-testid={`grants-${offer.id}`}>
              {grants.map((grant) => (
                <li key={grant.feature} className="flex items-start gap-2">
                  <span
                    aria-hidden="true"
                    className="mt-1.5 size-1.5 shrink-0 rounded-full bg-accent"
                  />
                  <span>{describeGrant(grant)}</span>
                </li>
              ))}
            </ul>
          )}

          {/* Pushed to the bottom so every card's button sits on one line
              across the row, however many features each of them lists. */}
          {/* `mt-auto` so every card's button sits on one line across the
              row, however many features each of them lists. */}
          <Button type="button" onClick={onChoose} className="mt-auto w-full">
            Choose
          </Button>
        </>
      )}
    </li>
  );
}

/** "monthly" reads better under a price than "MONTHLY" or "per MONTH". */
function billingPeriod(period: string): string {
  return period.toLowerCase().replace(/_/g, ' ');
}

/**
 * What one grant gives, in words.
 *
 * A boolean grant is the feature's name and nothing else — "Priority support",
 * not "Priority support: yes". A quota is its number and its unit, and
 * `unlimited` is spelled rather than shown as a missing limit: the contract
 * separates them precisely because a limit of zero is a real and different
 * answer.
 */
function describeGrant(grant: {
  name: string;
  kind: string;
  unit: string | null;
  limit: number | null;
  unlimited: boolean;
}): string {
  if (grant.kind !== 'QUOTA') {
    return grant.name;
  }

  if (grant.unlimited) {
    return `Unlimited ${grant.name.toLowerCase()}`;
  }

  if (grant.limit === null) {
    return grant.name;
  }

  const noun = grant.unit === null ? singular(grant.name.toLowerCase(), grant.limit) : grant.name.toLowerCase();

  return `${String(grant.limit)}${grant.unit === null ? '' : ` ${grant.unit}`} ${noun}`;
}

/**
 * "1 seat", not "1 seats".
 *
 * A feature is named in the plural by whoever created it — "Seats", "Projects" —
 * because that is how it reads in a catalogue. Beside the number 1 it reads
 * wrong, and a storefront is the one page where wrong English costs something.
 *
 * Trailing `s` only, and never after `ss`: this handles the names this catalogue
 * actually contains and deliberately does not attempt English. A name it cannot
 * fold is left exactly as its author wrote it, which is the safe failure — a
 * wrong plural is a blemish, an invented singular is a different word.
 */
function singular(noun: string, count: number): string {
  if (count !== 1 || !noun.endsWith('s') || noun.endsWith('ss')) {
    return noun;
  }

  return noun.slice(0, -1);
}
