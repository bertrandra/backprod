import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { withRoot } from '@/app/root';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useOffers, usePlans, useProductCatalogue, type Offer } from '@/queries/catalogue';
import { useOpenCheckoutSession, type OpenedCheckoutSession } from '@/queries/checkout';
import { useSession } from '@/queries/session';
import { useSessionStore } from '@/state/session';
import { useSubscription } from '@/queries/subscription';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';
import { t } from '@/i18n';
import { billingPeriod } from '@/ui/period';

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
 * **One way out of this screen, and it sells a seat** (2026-09-25). Buying is
 * gated on `billing.pay`, which since 2026-09-25 is the **USER's alone**: an
 * administrator administers, and was being offered *Buy for yourself* on every
 * offer in the catalogue until the operator asked what it was doing there
 * (ADR-055 §2b). Issuing, crediting and refunding stay `billing.manage` /
 * `payments.manage`, and recording money that arrived is `billing.manage` too.
 * Somebody who may only read the catalogue sees prices and no button.
 *
 * This paragraph said "which both tenant roles hold" for a day after the
 * migration that stopped it being true — which is how the next person
 * debugging a missing button gets sent to look somewhere else.
 *
 * Two other ways out stood here until today, and both sold to the
 * *organisation*: a quote (`sales.manage`) and "Buy for the organisation"
 * (`billing.manage`). The tenant surface no longer sells that, so they are
 * gone from the contract first and from here second — the order that keeps
 * this screen from being the only thing standing between a customer and a
 * purchase the platform means to refuse.
 *
 * **A person holds one live seat per product**, and the API refuses a second
 * (409 SEAT_ALREADY_ACTIVE) — so the button goes away on that fact, read from
 * `/subscription`. **And says why**, twice: a notice at the top naming what is
 * live, and in the row the sentence standing where the button was. A button
 * that vanishes without a word reads as a screen that lost something (the
 * operator's report, 2026-09-18), not as a purchase already made.
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
  const checkout = useOpenCheckoutSession();
  // Read only where it may be: the query itself needs `subscription.read`,
  // and asking without it is a 403 for nothing.
  const subscription = useSubscription(can(session, 'subscription.read'));
  const root = useSessionStore((state) => state.root);

  // What it would refuse is not offered: the operator's rule, since the
  // Quote button beside Buy — a function a person cannot use is hidden, not
  // shown and then declined. Changing what is subscribed is the subscription
  // screen's job. Until the read has answered, nobody is offered a button
  // that may vanish.
  const seat = subscription.data?.seat ?? null;
  const seated = seat !== null && seat.status === 'ACTIVE';
  const settled = !subscription.isPending || !can(session, 'subscription.read');

  const couldBuySeat = can(session, 'billing.pay');
  const mayBuySeat = settled && !seated && couldBuySeat;

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
      <div className="max-w-lg space-y-6" data-testid="catalogue-pay" data-seat={true}>
        <header className="space-y-1">
          <h1 className="text-2xl font-semibold">{t("Pay")}</h1>
          <p className="text-sm text-muted">
            {bought.name}, {t("for yourself")} — {t("your seat")} {t("starts when the payment is confirmed.")}</p>
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
            {t("Continue to your order")}</Link>
          {order.client_secret !== null && order.client_secret !== undefined && ' — it can be paid from there later.'}
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-4xl space-y-6">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-2xl font-semibold">{t("Catalogue")}</h1>
        {catalogue.data !== undefined && (
          <span className="text-sm text-muted">
            {catalogue.data.product.name}
          </span>
        )}
      </div>

      {checkout.error !== null && <ErrorSurface error={checkout.error} />}

      {/* The notice explains a button *this person* would otherwise have had
          (2026-09-18). A button that vanishes without a word reads as a screen
          that lost something, not as a purchase already made. */}
      {seated && seat !== null && couldBuySeat && (
        <section data-testid="already-seated" className={`${notice('info')} space-y-1 text-sm`}>
          <p className="font-medium">
            {t("You already hold a seat on")}{' '}{catalogue.data?.product.name ?? t("this product")}: {seat.offer.name}.
          </p>
          <p>
            {t("A person holds one seat per product, so nothing here is offered for yourself again.")}{' '}
            <Link to="/subscription" className="underline underline-offset-2">
              {t("Give it up from the subscription")}</Link>{' '}
            {t("to take another.")}</p>
        </section>
      )}

      {offers.data.length === 0 ? (
        <EmptyState
          title={t("Nothing is on sale")}
          description={t("No offer has a published version yet. Publishing one puts it here.")}
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
                    {plan.code} {t("· rank")}{' '}{plan.rank}
                  </span>
                </div>

                <ul className="space-y-2">
                  {planOffers.map((offer) => (
                    <OfferRow
                      key={offer.id}
                      offer={offer}
                      mayBuySeat={mayBuySeat}
                      seatTaken={settled && seated && couldBuySeat}
                      buying={checkout.isPending}
                      onBuy={() =>
                        checkout.mutate(
                          { offerId: offer.id },
                          {
                            onSuccess: (session) => {
                              setOpened({ session, offer });
                            },
                          },
                        )
                      }
                    />
                  ))}
                </ul>
              </section>
            ))}

          {orphaned.length > 0 && (
            <section className="space-y-3">
              <h2 className="text-xl font-semibold">{t("Other offers")}</h2>
              <p className="text-sm text-muted">
                {t("On sale, but their plan is not in the plan list — shown rather than hidden.")}</p>
              <ul className="space-y-2">
                {orphaned.map((offer) => (
                  <OfferRow
                    key={offer.id}
                    offer={offer}
                    mayBuySeat={false}
                    seatTaken={false}
                    buying={false}
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
  mayBuySeat,
  seatTaken,
  buying,
  onBuy,
}: {
  offer: Offer;
  mayBuySeat: boolean;
  /** The seat button is withheld because the person's seat is live: say so where it was. */
  seatTaken: boolean;
  buying: boolean;
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
          {version !== null && ` · ${billingPeriod(version.billing_period)} · v${version.version}`}
        </p>
      </div>

      {version === null ? (
        // Typed nullable, so said plainly. A price rendered from nothing would
        // be a zero, and a zero is a legitimate price — the two must not look
        // alike.
        <p data-testid="no-sellable-version" className="text-sm text-subtle">
          {t("No sellable version")}</p>
      ) : (
        <>
          <span className="flex items-baseline gap-1.5">
            <Amount money={version.price} className="text-sm font-medium" />
            {/* Said wherever the price can be acted on (2026-09-24). An
                offer's price is the taxable base and VAT is calculated on
                top of it at invoicing (§25.3), so a Buy beside a silent
                figure quotes a number the customer will not be charged.
                Withheld where the row is read-only, because there is then no
                purchase to mislead. */}
            {mayBuySeat && (
              <span data-testid="price-excludes-tax" className="text-xs text-subtle">
                {t("excl. VAT")}</span>
            )}
          </span>

          <div className="mt-2 flex flex-wrap items-center gap-2 md:mt-0">
            {seatTaken && (
              <span data-testid="seat-taken" className="text-xs text-muted">
                {t("Your seat is live")}</span>
            )}
            {mayBuySeat && (
              <Button type="button" pending={buying} onClick={onBuy} data-testid="buy-seat">
                {t("Buy for yourself")}</Button>
            )}
          </div>
        </>
      )}
    </li>
  );
}
