import { useStorefront, type PublicOffer } from '@/queries/storefront';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
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
  onChoose,
  onSignIn,
}: {
  productCode: string | null;
  /** What choosing an offer does. Lot B makes it sign-up-then-buy. */
  onChoose: (offer: PublicOffer) => void;
  onSignIn: () => void;
}) {
  const storefront = useStorefront(productCode);

  return (
    <main className="mx-auto max-w-3xl space-y-8 p-4 py-10">
      <header className="space-y-2">
        <h1 className="text-2xl font-semibold">
          {storefront.data?.product?.name ?? 'What we sell'}
        </h1>
        <p className="text-sm text-muted">
          Choose a plan to get started. You will create your account as part of the purchase —
          there is nothing to set up first.
        </p>
      </header>

      {productCode === null || productCode === '' ? (
        // A storefront with no product is not an error to apologise for: it is
        // a deployment whose default product was never configured, and the
        // person reading this page cannot fix it. Said plainly, without a
        // stack trace and without pretending the shop is empty.
        <EmptyState
          title="No product selected"
          description="Add ?product=your-product-code to the address, or set VITE_DEFAULT_PRODUCT when building."
        />
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
        <ul className="space-y-3" data-testid="storefront-offers">
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

function OfferCard({ offer, onChoose }: { offer: PublicOffer; onChoose: () => void }) {
  const version = offer.version;

  return (
    <li
      data-offer={offer.id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise sm:flex sm:items-center sm:gap-4"
    >
      <div className="min-w-0 sm:flex-1">
        <p className="font-medium">{offer.name}</p>
        <p className="mt-1 text-xs text-muted">
          {offer.plan.name}
          {version !== null && ` · billed ${version.billing_period.toLowerCase()}`}
        </p>
      </div>

      {version === null ? (
        // Typed nullable in the contract, so said plainly rather than
        // rendered as a zero — and a zero is a legitimate price.
        <p data-testid="no-price" className="mt-3 text-sm text-subtle sm:mt-0">
          Price on request
        </p>
      ) : (
        <div className="mt-3 flex items-center gap-4 sm:mt-0">
          <Amount money={version.price} className="text-base font-medium" />
          <Button type="button" onClick={onChoose}>
            Choose
          </Button>
        </div>
      )}
    </li>
  );
}
