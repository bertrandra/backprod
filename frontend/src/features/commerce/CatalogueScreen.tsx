import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { withRoot } from '@/app/root';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useOffers, usePlans, useProductCatalogue, type Offer } from '@/queries/catalogue';
import { useOpenCheckoutSession, type OpenedCheckoutSession } from '@/queries/checkout';
import { useCreateQuote } from '@/queries/sales';
import { useSession } from '@/queries/session';
import { useSessionStore } from '@/state/session';
import { useSubscription } from '@/queries/subscription';
import { useTaxProfile } from '@/queries/tax';
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
 *
 * **A quote is offered to a business.** The server refuses one for a tenant
 * whose tax profile does not say B2B (`QUOTE_REQUIRES_BUSINESS_CUSTOMER`);
 * the button is hidden on the same fact, read from the profile, so a person
 * who signed up for themselves is not offered a document they cannot have.
 * Hiding is courtesy — the API is the authority — and it is *not* a gate on
 * a role or a plan: it is the customer's own declared kind (CLAUDE.md,
 * "Gating is data").
 *
 * **Buying pays here.** The checkout's `client_secret` is returned once and
 * never recoverable (ADR-034), and `/checkout/{id}` is the status page a
 * reload lands on, not a place that can hold it (ADR-048). So the form is
 * offered on this screen, where the secret was born — the same panel the
 * storefront, the invoice and the payments list use — and the order page is
 * where the form hands over to. Navigating straight to the order, as this
 * screen did until a real purchase tried it, threw the secret away and left
 * an order nobody could pay.
 */
export function CatalogueScreen() {
  const navigate = useNavigate();
  const { data: session } = useSession();
  const offers = useOffers();
  const plans = usePlans();
  const catalogue = useProductCatalogue(session?.productId ?? null);
  const quote = useCreateQuote();
  const checkout = useOpenCheckoutSession();
  const taxProfile = useTaxProfile();
  // Read only where it may be: the query itself needs `subscription.read`,
  // and asking without it is a 403 for nothing.
  const subscription = useSubscription(can(session, 'subscription.read'));
  const root = useSessionStore((state) => state.root);

  // One live subscription per product, and the API refuses a second order
  // (409 SUBSCRIPTION_ALREADY_ACTIVE). What it would refuse is not offered:
  // the operator's rule, since the Quote button beside Buy — a function a
  // person cannot use is hidden, not shown and then declined. Changing what
  // is subscribed is the subscription screen's job. Until the read has
  // answered, nobody is offered a button that may vanish.
  const live = subscription.data?.subscription ?? null;
  const subscribed = live !== null && live.status === 'ACTIVE';
  const settled = !subscription.isPending || !can(session, 'subscription.read');

  // Both halves, and only both: the permission says who may raise one, the
  // profile says whether this customer is one that gets one.
  const maySell = settled && !subscribed && can(session, 'sales.manage') && taxProfile.data?.customer_kind === 'B2B';
  const mayBuy = settled && !subscribed && can(session, 'billing.manage');

  // The checkout just opened, held for exactly as long as the render that
  // offers the form (ADR-034): never in a store, never across a navigation.
  const [opened, setOpened] = useState<{ session: OpenedCheckoutSession; offer: Offer } | null>(null);

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

  if (opened !== null) {
    const { session: order, offer: bought } = opened;
    const statusPage = { to: '/checkout/$sessionId' as const, params: { sessionId: order.id } };

    return (
      <div className="max-w-lg space-y-6" data-testid="catalogue-pay">
        <header className="space-y-1">
          <h1 className="text-2xl font-semibold">Pay</h1>
          <p className="text-sm text-muted">
            {bought.name} — the subscription starts when the payment is confirmed.
          </p>
        </header>

        <PaymentElementPanel
          provider={order.payment_provider}
          clientSecret={order.client_secret}
          amount={order.gross}
          returnUrl={new URL(withRoot(root, `/checkout/${order.id}`), window.location.origin).toString()}
          // Whatever the form said, the order page says what the server knows.
          onSettled={() => void navigate(statusPage)}
        />

        {/* Always there: a free offer has nothing to pay, a provider with no
            card form confirms on its own, and somebody who changes their mind
            still has an order to come back to (ADR-034). */}
        <p className="text-sm text-muted">
          <Link {...statusPage} data-testid="continue-to-order" className="underline underline-offset-2">
            Continue to your order
          </Link>
          {order.client_secret !== null && order.client_secret !== undefined && ' — it can be paid from there later.'}
        </p>
      </div>
    );
  }

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

      {subscribed && live !== null && (
        <p data-testid="already-subscribed" className="text-sm text-muted">
          Subscribed to <span className="font-medium text-ink">{live.offer.name}</span>.
          {' '}
          <Link to="/subscription" className="underline underline-offset-2">
            Change it from the subscription
          </Link>
          {' '}— an organisation holds one subscription per product, so nothing here can be bought beside it.
        </p>
      )}

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
                          onSuccess: (session) => {
                            setOpened({ session, offer });
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
