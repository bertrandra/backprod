import { Link } from '@tanstack/react-router';

import { useSetOfferPublicListing, useStorefrontOffers } from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { useSessionStore } from '@/state/session';
import { PageHeader } from '@/ui/Page';

/**
 * `console.admin.storefront` — what the public page advertises.
 *
 * **Being on sale and being advertised are two decisions.** An offer
 * negotiated with one reseller, a grandfathered price a subscription still
 * renews on, a plan the sales desk quotes by hand — all are legitimately on
 * sale and none belong on a page anybody can open. The version's window says
 * *when* an offer may be sold; this says *whether it may be shown*, and
 * nothing else on the platform can say it.
 *
 * **Behind `staff.catalog.manage`, not `catalog.manage`.** ADR-040 lets the
 * platform lend the latter to a tenant so they can author their own offers; a
 * tenant doing that must not thereby decide what the platform's front page
 * shows to everybody. The two authorities separate here for the same reason
 * they separated there.
 *
 * Every offer is listed, hidden ones included — choosing what to advertise
 * means seeing what you are choosing between.
 *
 * **The product comes from the switcher in the bar** (ADR-047). It cannot come
 * from a membership: a platform role grants none (non-negotiable #22), so on
 * the console the switcher lists every product the platform hosts instead,
 * and this screen reads what it chose. Reading the browser's remembered
 * tenant-app product — which this screen did when it shipped — made the
 * console silently administer whichever product the person had last used the
 * *application* in; `?selected=` in the URL, which followed, left two products
 * on one screen. One switcher, visible, fed by the platform's own list, is
 * the honest place for it.
 */
export function StorefrontScreen() {
  const productCode = useSessionStore((state) => state.productCode);
  const offers = useStorefrontOffers(productCode);
  const decide = useSetOfferPublicListing(productCode ?? '');

  if (productCode === null || productCode === '') {
    return (
      <EmptyState
        title="No product chosen"
        description="The storefront is per product. Choose one in the bar above — the switcher there lists every product the platform hosts."
        action={
          <Link
            to="/console/products"
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
          >
            Go to Products
          </Link>
        }
      />
    );
  }

  if (offers.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (offers.error !== null) {
    return <ErrorSurface error={offers.error} onRetry={() => void offers.refetch()} />;
  }

  const listed = offers.data.offers.filter((offer) => offer.publicly_listed).length;

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title="Storefront"
        // The count sits on the title's baseline rather than under the
        // paragraph: it is a fact about what is on screen, not part of the
        // explanation, and reading the explanation to find it was backwards.
        meta={
          <span data-testid="advertised-count">
            {listed} of {offers.data.offers.length} advertised publicly
          </span>
        }
        description={
          <>
            What somebody with no account sees for <strong>{offers.data.product.name}</strong>.
            Being on sale and being advertised are different decisions: an offer withdrawn from
            here stays sellable, and everybody already subscribed to it keeps their terms.
          </>
        }
      />

      {decide.error !== null && <ErrorSurface error={decide.error} />}

      {offers.data.offers.length === 0 ? (
        <EmptyState
          title="Nothing to advertise"
          description="This product has no offers yet. Authoring one makes it available here."
        />
      ) : (
        <ul className="space-y-2" data-testid="storefront-offers">
          {offers.data.offers.map((offer) => (
            <li
              key={offer.id}
              data-offer={offer.id}
              data-advertised={offer.publicly_listed ? 'true' : 'false'}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm sm:flex sm:items-center sm:gap-4"
            >
              <div className="min-w-0 sm:flex-1">
                <p className="font-medium">{offer.name}</p>
                <p className="text-xs text-muted">
                  <code>{offer.code}</code> · {offer.plan.name} · {offer.versions.length} version
                  {offer.versions.length === 1 ? '' : 's'}
                </p>
              </div>

              <div className="mt-3 flex items-center gap-3 sm:mt-0">
                <span
                  data-testid="listing-state"
                  className="text-xs text-muted"
                >
                  {offer.publicly_listed ? 'On the public page' : 'Not advertised'}
                </span>

                <Button
                  type="button"
                  variant={offer.publicly_listed ? 'danger' : 'primary'}
                  pending={decide.isPending}
                  onClick={() =>
                    decide.mutate({ offerId: offer.id, listed: !offer.publicly_listed })
                  }
                >
                  {offer.publicly_listed ? 'Withdraw' : 'Advertise'}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
