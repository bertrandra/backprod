import { Link } from '@tanstack/react-router';

import { useViewState } from '@/app/frame/viewState';
import { useSetOfferPublicListing, useStorefrontOffers } from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

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
 * **The product comes from the URL**, as `?selected=`, the same way
 * `console.support.tenants` carries the tenant somebody opened. It cannot come
 * from an ambient context: a platform role grants no membership
 * (non-negotiable #22), so §12.1's ambient product does not exist on this
 * shell. Reading it from the browser's remembered tenant-app product — which
 * this screen did when it shipped — made the console silently administer
 * whichever product the person had last used the *application* in, and left it
 * with nothing to show for anybody who had never opened the application at
 * all. The URL is the honest place for it: it says which product is being
 * administered, and a link opens the same one for whoever follows it.
 */
export function StorefrontScreen() {
  const { selected } = useViewState();
  const productCode = selected ?? null;
  const offers = useStorefrontOffers(productCode);
  const decide = useSetOfferPublicListing(productCode ?? '');

  if (productCode === null || productCode === '') {
    return (
      <EmptyState
        title="No product chosen"
        description="The storefront is per product, and the console has no default. Pick one from Products."
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
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Storefront</h1>
        <p className="text-sm text-muted">
          What somebody with no account sees for <strong>{offers.data.product.name}</strong>. Being
          on sale and being advertised are different decisions: an offer withdrawn from here stays
          sellable, and everybody already subscribed to it keeps their terms.
        </p>
        <p data-testid="advertised-count" className="text-xs text-subtle">
          {listed} of {offers.data.offers.length} advertised publicly.
        </p>
      </header>

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
