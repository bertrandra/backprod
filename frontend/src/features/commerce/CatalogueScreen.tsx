import { useNavigate } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { useOffers, usePlans, useProductCatalogue, type Offer } from '@/queries/catalogue';
import { useOpenCheckoutSession } from '@/queries/checkout';
import { useCreateQuote } from '@/queries/sales';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `commerce.catalogue` — what is on sale.
 *
 * **The read that must never branch on a product or a plan name** (§6, §13,
 * non-negotiable #25). Offers are grouped by plan and the plans are ordered by
 * `rank`, which is the only ordering that exists: an upgrade is a comparison of
 * two integers, never of two words. Nothing here knows that PRO is better than
 * STARTER, and nothing here should.
 *
 * `Offer.version` is nullable in the contract — "null only in principle, but
 * typed honestly rather than asserted away" — so an offer with nothing sellable
 * says so instead of rendering a price it does not have.
 *
 * Two ways out of this screen, both gated on the permission that actually
 * governs them: a quote (`sales.manage`) and a checkout (`billing.manage`).
 * Someone who may only read the catalogue sees prices and no buttons.
 */
export function CatalogueScreen() {
  const navigate = useNavigate();
  const { data: session } = useSession();
  const offers = useOffers();
  const plans = usePlans();
  const catalogue = useProductCatalogue(session?.productId ?? null);
  const quote = useCreateQuote();
  const checkout = useOpenCheckoutSession();

  const maySell = can(session, 'sales.manage');
  const mayBuy = can(session, 'billing.manage');

  if (offers.isPending || plans.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (offers.error !== null) {
    return <ErrorSurface error={offers.error} onRetry={() => void offers.refetch()} />;
  }

  if (plans.error !== null) {
    return <ErrorSurface error={plans.error} onRetry={() => void plans.refetch()} />;
  }

  // Grouped by plan, in rank order. An offer whose plan is not in the list still
  // appears — dropping it would hide something that is genuinely on sale.
  const byPlan = plans.data.map((plan) => ({
    plan,
    offers: offers.data.filter((offer) => offer.plan.id === plan.id),
  }));

  const orphaned = offers.data.filter(
    (offer) => !plans.data.some((plan) => plan.id === offer.plan.id),
  );

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-2xl font-semibold">Catalogue</h1>
        {catalogue.data !== undefined && (
          <span className="text-sm text-muted">
            {catalogue.data.product.name}
          </span>
        )}
      </div>

      {quote.error !== null && <ErrorSurface error={quote.error} />}
      {checkout.error !== null && <ErrorSurface error={checkout.error} />}

      {offers.data.length === 0 ? (
        <EmptyState
          title="Nothing is on sale"
          description="No offer has a published version yet. Publishing one puts it here."
        />
      ) : (
        <div className="space-y-8">
          {byPlan
            .filter((group) => group.offers.length > 0)
            .map(({ plan, offers: planOffers }) => (
              <section key={plan.id} data-plan={plan.code} className="space-y-3">
                <div className="flex flex-wrap items-baseline gap-2">
                  <h2 className="text-xl font-semibold">{plan.name}</h2>
                  {/* The rank is shown because it is the real ordering, and
                      seeing it makes the sequence explicable rather than magic. */}
                  <span className="text-xs text-subtle">
                    {plan.code} · rank {plan.rank}
                  </span>
                </div>

                <ul className="space-y-2">
                  {planOffers.map((offer) => (
                    <OfferRow
                      key={offer.id}
                      offer={offer}
                      maySell={maySell}
                      mayBuy={mayBuy}
                      quoting={quote.isPending}
                      buying={checkout.isPending}
                      onQuote={() =>
                        quote.mutate(
                          { offer_id: offer.id },
                          {
                            onSuccess: (created) => {
                              void navigate({
                                to: '/quotes',
                                search: { selected: created.id },
                              });
                            },
                          },
                        )
                      }
                      onBuy={() =>
                        checkout.mutate(offer.id, {
                          onSuccess: (opened) => {
                            void navigate({
                              to: '/checkout/$sessionId',
                              params: { sessionId: opened.id },
                            });
                          },
                        })
                      }
                    />
                  ))}
                </ul>
              </section>
            ))}

          {orphaned.length > 0 && (
            <section className="space-y-3">
              <h2 className="text-xl font-semibold">Other offers</h2>
              <p className="text-sm text-muted">
                On sale, but their plan is not in the plan list — shown rather than hidden.
              </p>
              <ul className="space-y-2">
                {orphaned.map((offer) => (
                  <OfferRow
                    key={offer.id}
                    offer={offer}
                    maySell={false}
                    mayBuy={false}
                    quoting={false}
                    buying={false}
                    onQuote={() => undefined}
                    onBuy={() => undefined}
                  />
                ))}
              </ul>
            </section>
          )}
        </div>
      )}
    </div>
  );
}

function OfferRow({
  offer,
  maySell,
  mayBuy,
  quoting,
  buying,
  onQuote,
  onBuy,
}: {
  offer: Offer;
  maySell: boolean;
  mayBuy: boolean;
  quoting: boolean;
  buying: boolean;
  onQuote: () => void;
  onBuy: () => void;
}) {
  const version = offer.version;

  return (
    <li
      data-offer={offer.id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise md:flex md:items-center md:gap-4"
    >
      <div className="min-w-0 md:flex-1">
        <p className="font-medium">{offer.name}</p>
        <p className="text-xs text-muted">
          <code>{offer.code}</code>
          {version !== null && ` · ${version.billing_period.toLowerCase()} · v${version.version}`}
        </p>
      </div>

      {version === null ? (
        // Typed nullable, so said plainly. A price rendered from nothing would
        // be a zero, and a zero is a legitimate price — the two must not look
        // alike.
        <p data-testid="no-sellable-version" className="text-sm text-subtle">
          No sellable version
        </p>
      ) : (
        <>
          <Amount money={version.price} className="text-sm font-medium" />

          <div className="mt-2 flex flex-wrap gap-2 md:mt-0">
            {maySell && (
              <Button type="button" variant="secondary" pending={quoting} onClick={onQuote}>
                Quote
              </Button>
            )}
            {mayBuy && (
              <Button type="button" pending={buying} onClick={onBuy}>
                Buy
              </Button>
            )}
          </div>
        </>
      )}
    </li>
  );
}
