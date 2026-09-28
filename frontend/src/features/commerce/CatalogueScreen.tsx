import { Link, useNavigate } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { withRoot } from '@/app/root';
import { CancellationOutcome } from '@/features/billing/decisions';
import { PaymentElementPanel } from '@/features/commerce/payment/PaymentElementPanel';
import { useOffers, usePlans, useProductCatalogue, type Offer } from '@/queries/catalogue';
import { useOpenCheckoutSession, type OpenedCheckoutSession } from '@/queries/checkout';
import { useStartPayment, type StartedPayment } from '@/queries/payments';
import { useSession } from '@/queries/session';
import { useSessionStore } from '@/state/session';
import {
  useCancelScheduledChange,
  useCancelSubscription,
  useChangeOffer,
  usePreviewOfferChange,
  useSchedule,
  useScheduleOfferChange,
  useStartFreemium,
  useSubscription,
  type CancellationDecision,
  type ChangeDecision,
  type Subscription,
} from '@/queries/subscription';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice } from '@/ui/tone';
import { currentLocale, t } from '@/i18n';
import { tx } from '@/i18n/react';
import { billingPeriod } from '@/ui/period';

/**
 * `commerce.catalogue` — what is on sale, and what each offer would do to
 * what the reader already holds (spec §7, étape 7).
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
 * **Six things a row can be, and rank decides which** (§7's table). No
 * subscription: buy it, or take the free period if that is what it is. A seat
 * already held: this is the plan you are on, the move you have already asked
 * for, a move **up** — immediate and priced — or a move **down**, which waits
 * for the end of the period already paid for. Which of the last two is a
 * comparison of `plan.rank`, and the two call **different operations**:
 * `changeOffer` and `scheduleOfferChange`. Sending the immediate one for a
 * move down would ask the server to do something it refuses
 * (`NOT_A_DOWNGRADE`), and reading a name instead of a rank is what
 * `gate:plans` forbids in PHP and §13 forbids everywhere.
 *
 * **Every figure is the server's.** The amount payable today, the credit and
 * the date all come from `previewOfferChange`, asked once per row — a query,
 * because it writes nothing — and rendered as it arrives. Nothing here
 * subtracts a credit from a charge: §4 and §25 say every total on screen is the
 * server's, and the point is not tidiness, it is that the preview and the act
 * share one calculation server-side, so the figure shown is the figure charged.
 * A screen doing its own arithmetic would be right until rounding, and then
 * would quote a price no invoice carries.
 *
 * **The free period is spent, and the screen says so before the click**
 * (§6.4, §6.2). It is given once per account per product, whatever became of
 * it, and `freemium_used` is the server's answer to that. Two rows depend on
 * it and the second is the one that used to mislead: the free offer itself,
 * where there is nothing to take; and for somebody holding the cheapest paid
 * plan, the *move down* to it — which is not a change of plan at all but a
 * **cancellation**, and finding that out from a 409 after choosing it is what
 * §6.2 asks this screen to prevent. Hiding stays courtesy: `previewOfferChange`
 * and both change doors refuse it too.
 *
 * **One way out that acquires, and it sells a seat** (2026-09-25). Buying is
 * gated on `billing.pay`, which since 2026-09-25 is the **USER's alone**: an
 * administrator administers, and was being offered *Buy for yourself* on every
 * offer in the catalogue until the operator asked what it was doing there
 * (ADR-055 §2b). Taking the free period is the same permission, because it is
 * the same act with nothing to pay. Changing what is already held is
 * `subscription.manage`. Somebody who may only read the catalogue sees prices
 * and no button.
 *
 * **And every one of those operations names the seat with a flag** (§13.1).
 * `useChangeOffer(true)`, `useScheduleOfferChange(true)`,
 * `useCancelScheduledChange(true)`, `usePreviewOfferChange(…, true)` and
 * `cancel.mutate({ seat: true })`. Without it they address the *organisation's*
 * subscription — which the tenant surface no longer sells (ADR-055), so the
 * customer holds none and every button on this screen would answer
 * `NO_SUBSCRIPTION` about something they never had.
 *
 * **A person holds one live seat per product**, and the API refuses a second
 * (409 SEAT_ALREADY_ACTIVE) — so the buying button goes away on that fact, read
 * from `/subscription`. **And says why**: a notice at the top naming what is
 * live, and in the row the plan they are on standing where the button was. A
 * button that vanishes without a word reads as a screen that lost something
 * (the operator's report, 2026-09-18), not as a purchase already made.
 *
 * **Buying pays here.** The checkout's `client_secret` is returned once and
 * never recoverable (ADR-034), and `/checkout/{id}` is the status page a
 * reload lands on, not a place that can hold it (ADR-048). So the form is
 * offered on this screen, where the secret was born — the same panel the
 * storefront, the invoice and the payments list use — and the order page is
 * where the form hands over to. Navigating straight to the order, as this
 * screen did until a real purchase tried it, threw the secret away and left
 * an order nobody could pay.
 *
 * **Nothing here is optimistic**, and `gate:money` proves it rather than
 * trusting it: a move up raises an invoice with a gapless legal number and
 * sends a refund back through a provider, and a screen that assumed success
 * would have invented both documents.
 *
 * **And changing a plan pays for itself, here** (2026-09-28). The server has
 * always answered `charge_invoice_id` and `credit_refund_id`; this screen
 * dropped both on the floor. So a customer moved up, a numbered invoice came
 * into existence, and nobody was ever asked to pay it — it sat until the
 * dunning pass noticed days later and shut their workshop (ADR-060). The
 * money for a change now comes out at the moment the change is made, which is
 * the moment the customer has already decided to spend it, through the same
 * panel buying uses. §24 is why it has to be asked for at all: this platform
 * holds no instrument, so there is no card here to charge by itself.
 *
 * The credit is the other half and is **already money that has moved** — a
 * refund with its credit note (ADR-058), sent before the plan moved. It is
 * reported rather than offered: there is nothing for the customer to do about
 * it, and a screen silent about a repayment is a screen the customer has to
 * check their bank to understand.
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
  const mayRead = can(session, 'subscription.read');
  const subscription = useSubscription(mayRead);
  const root = useSessionStore((state) => state.root);

  const freemium = useStartFreemium();
  // `true` on every one of them: a customer holds a seat and never the
  // organisation's subscription (ADR-055), and the flag is how that is said —
  // never an id, because the only two subscribers are the tenant and the
  // caller and both come from the context (§13.1).
  const changeOffer = useChangeOffer(true);
  const scheduleChange = useScheduleOfferChange(true);
  const cancelScheduled = useCancelScheduledChange(true);
  const cancel = useCancelSubscription();
  const settle = useStartPayment();

  // What it would refuse is not offered: the operator's rule, since the
  // Quote button beside Buy — a function a person cannot use is hidden, not
  // shown and then declined. Until the read has answered, nobody is offered a
  // button that may vanish.
  const seat = subscription.data?.seat ?? null;
  const seated = seat !== null && seat.status === 'ACTIVE';
  const settled = !subscription.isPending || !mayRead;
  // The seat every row is compared against — the live one, and null while the
  // read is still in flight. A cancelled seat is not a plan somebody is on.
  const held = seated ? seat : null;

  // The server's fact, and the only one that can be trusted here: a free
  // period that expired six months ago still forbids another (§6.4), so
  // nothing about the seat in hand answers this question.
  const freemiumSpent = subscription.data?.freemium_used === true;

  const couldBuySeat = can(session, 'billing.pay');
  const mayBuySeat = settled && !seated && couldBuySeat;
  const mayChangeSeat = settled && held !== null && can(session, 'subscription.manage');

  // What leaving would decide, for the **seat** and not the organisation
  // (§7's last row). Asked only where there is a seat and somebody who could
  // act on the answer: `?seat=1` against no seat is `NO_SUBSCRIPTION`, and a
  // decision nobody is offered is a refused request per visit.
  const schedule = useSchedule(true, mayChangeSeat && mayRead);

  // The checkout just opened, held for exactly as long as the render that
  // offers the form (ADR-034): never in a store, never across a navigation.
  const [opened, setOpened] = useState<{ session: OpenedCheckoutSession; offer: Offer } | null>(null);

  // What a change or a departure just did, and the payment for it once the
  // server has answered. `payment` is null until then and the form is simply
  // not drawn: waiting is the honest state, and a panel rendered ahead of the
  // secret would be the optimism `gate:money` forbids.
  const [outcome, setOutcome] = useState<Outcome | null>(null);

  const settleInvoice = (invoiceId: string) => {
    settle.mutate(invoiceId, {
      onSuccess: (payment) => {
        setOutcome((current) => (current === null ? current : { ...current, payment }));
      },
    });
  };

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

  if (outcome !== null) {
    const invoiceId = outcome.invoiceId;

    return (
      <div className="max-w-lg space-y-6" data-testid="catalogue-settle">
        <header className="space-y-1">
          <h1 className="text-2xl font-semibold">{outcome.heading}</h1>
          <p className="text-sm text-muted" data-testid="settle-what">{outcome.what}</p>
        </header>

        {/* The repayment, stated. Already sent when the server answered, so it
            is in the past tense and carries no button. */}
        {outcome.credited !== null && (
          <p className="text-sm" data-testid="settle-credit">
            {t("Returned to the way you paid:")}{' '}
            <Amount
              money={{
                minor_units: outcome.credited.credit_minor_units,
                currency: outcome.credited.currency,
              }}
              className="font-medium"
            />
          </p>
        )}

        {settle.error !== null && <ErrorSurface error={settle.error} />}

        {invoiceId !== null && outcome.payment !== null && (
          <PaymentElementPanel
            provider={outcome.payment.payment_provider}
            clientSecret={outcome.payment.client_secret}
            amount={outcome.payment.amount}
            returnUrl={new URL(withRoot(root, `/invoices/${invoiceId}`), window.location.origin).toString()}
            // Whatever the form said, the invoice says what the server knows.
            onSettled={() =>
              void navigate({ to: '/invoices/$invoiceId', params: { invoiceId } })
            }
          />
        )}

        {invoiceId !== null && (
          // Always there, for the same reason the order link is: a provider
          // with no card form confirms on its own, and somebody who changes
          // their mind still has a document to come back to. Without it the
          // only remaining route to this invoice was the dunning notice.
          <p className="text-sm text-muted">
            <Link
              to="/invoices/$invoiceId"
              params={{ invoiceId }}
              data-testid="continue-to-invoice"
              className="underline underline-offset-2"
            >
              {t("Open the invoice")}</Link>
            {' — '}{t("it can be paid from there later.")}
          </p>
        )}

        <p className="text-sm">
          <button
            type="button"
            data-testid="back-to-catalogue"
            className="underline underline-offset-2"
            onClick={() => setOutcome(null)}
          >
            {t("Back to the catalogue")}</button>
        </p>
      </div>
    );
  }

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

  const row = (offer: Offer) => (
    <OfferRow
      key={offer.id}
      offer={offer}
      standing={standingOf(offer, held, freemiumSpent)}
      mayBuySeat={mayBuySeat}
      mayChangeSeat={mayChangeSeat}
      mayRead={mayRead}
      pending={held?.pending ?? null}
      cancellation={schedule.data?.if_cancelled_now}
      buying={checkout.isPending}
      acting={
        (changeOffer.isPending && changeOffer.variables === offer.id) ||
        (scheduleChange.isPending && scheduleChange.variables === offer.id) ||
        (freemium.isPending && freemium.variables === offer.id) ||
        cancelScheduled.isPending ||
        cancel.isPending
      }
      error={
        changeOffer.variables === offer.id
          ? changeOffer.error
          : scheduleChange.variables === offer.id
            ? scheduleChange.error
            : freemium.variables === offer.id
              ? freemium.error
              : null
      }
      onBuy={() =>
        checkout.mutate(
          { offerId: offer.id },
          { onSuccess: (session) => { setOpened({ session, offer }); } },
        )
      }
      onTakeFreePeriod={() => freemium.mutate(offer.id)}
      onMoveUp={() =>
        changeOffer.mutate(offer.id, {
          onSuccess: (moved) => {
            const change = moved.change;
            // `?? null` and not a bare read: the contract requires both, so
            // absent means a response that did not come from this server, and
            // the screen treats that as "nothing" rather than as a document.
            const charge = change.charge_invoice_id ?? null;
            const credited = (change.credit_refund_id ?? null) !== null ? change : null;

            // A move that costs nothing and returns nothing has nothing to
            // report, and taking the screen over to say so would put a page
            // between the customer and the catalogue for no reason.
            if (charge === null && credited === null) {
              return;
            }

            setOutcome({
              heading: charge !== null ? t('Pay') : t('Done'),
              what: t('{plan} — the new period starts today.', { plan: offer.name }),
              credited,
              invoiceId: charge,
              payment: null,
            });

            if (charge !== null) {
              settleInvoice(charge);
            }
          },
        })
      }
      onMoveDown={() => scheduleChange.mutate(offer.id)}
      onWithdraw={() => cancelScheduled.mutate()}
      onCancel={() =>
        cancel.mutate(
          { seat: true },
          {
            onSuccess: (left) => {
              const charge = left.cancellation.charge_invoice_id ?? null;

              // A departure that costs nothing raises no document at all
              // (§6.3), and there is then nothing to show here: the decision
              // is already on the row that offered the button.
              if (charge === null) {
                return;
              }

              setOutcome({
                heading: t('Pay'),
                what: t('Leaving before the end of the commitment.'),
                credited: null,
                invoiceId: charge,
                payment: null,
              });

              settleInvoice(charge);
            },
          },
        )
      }
    />
  );

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
      {/* Withdrawing a scheduled change and giving a seat up are the two acts
          with no offer of their own to fail against, so their refusals are
          said once here. */}
      {cancelScheduled.error !== null && <ErrorSurface error={cancelScheduled.error} />}
      {cancel.error !== null && <ErrorSurface error={cancel.error} />}

      {/* The notice explains a button *this person* would otherwise have had
          (2026-09-18). A button that vanishes without a word reads as a screen
          that lost something, not as a purchase already made. Since 2026-09-27
          it also says what the rows now offer instead of buying. */}
      {held !== null && couldBuySeat && (
        <section data-testid="already-seated" className={`${notice('info')} space-y-1 text-sm`}>
          <p className="font-medium">
            {t("You already hold a seat on")}{' '}{catalogue.data?.product.name ?? t("this product")}: {held.offer.name}.
          </p>
          <p>
            {t("A person holds one seat per product, so nothing here is offered for yourself again.")}{' '}
            {t("Every other offer says what moving to it would do, and your own plan is where you can leave it.")}</p>
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

                <ul className="space-y-2">{planOffers.map(row)}</ul>
              </section>
            ))}

          {orphaned.length > 0 && (
            <section className="space-y-3">
              <h2 className="text-xl font-semibold">{t("Other offers")}</h2>
              <p className="text-sm text-muted">
                {t("On sale, but their plan is not in the plan list — shown rather than hidden.")}</p>
              <ul className="space-y-2">{orphaned.map(row)}</ul>
            </section>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * What one row of the catalogue is, against what the reader already holds
 * (spec §7).
 *
 * Six answers, and the one thing that decides between the last two is
 * `plan.rank` — two integers, never two names (§13, `gate:plans`). `LATERAL`
 * has no row of its own in §7's table and needs none: what decides whether
 * there is a period to cut short is that the change is *immediate*, not which
 * way the rank went, so an equal rank is `UP` — which is exactly what the
 * server does with it.
 *
 * `SPENT` is the answer §6.4 asks for. It is reached from **two** situations
 * and the second is the one that misled: the free offer with nobody on a seat,
 * where there is nothing left to take; and the move *down* onto it, which for
 * somebody whose free period is gone is a cancellation rather than a change of
 * plan. Both read `freemium_used`, which is the server's fact, and both doors
 * refuse it too — so withholding the button is courtesy and not a rule
 * invented here.
 */
type Standing = 'BUY' | 'FREE_PERIOD' | 'SPENT' | 'CURRENT' | 'PENDING' | 'UP' | 'DOWN';

function standingOf(offer: Offer, held: Subscription | null, freemiumSpent: boolean): Standing {
  // Whether this is the free period is the **server's** answer
  // (`OfferVersion.freemium`, spec §6): free and over when its period is. A
  // client working it out from the price and a renewal setting would be
  // keeping a copy of a business rule §4 does not let it hold, and comparing a
  // plan's code would be §13's forbidden branch.
  const free = offer.version?.freemium === true;

  if (held === null) {
    if (free) {
      return freemiumSpent ? 'SPENT' : 'FREE_PERIOD';
    }

    return 'BUY';
  }

  if (offer.id === held.offer.id) {
    return 'CURRENT';
  }

  if (held.pending?.offer_id === offer.id) {
    return 'PENDING';
  }

  if (free && freemiumSpent) {
    return 'SPENT';
  }

  return offer.plan.rank < held.offer.plan.rank ? 'DOWN' : 'UP';
}

function OfferRow({
  offer,
  standing,
  mayBuySeat,
  mayChangeSeat,
  mayRead,
  pending,
  cancellation,
  buying,
  acting,
  error,
  onBuy,
  onTakeFreePeriod,
  onMoveUp,
  onMoveDown,
  onWithdraw,
  onCancel,
}: {
  offer: Offer;
  standing: Standing;
  mayBuySeat: boolean;
  mayChangeSeat: boolean;
  /** Whether the preview may be asked for at all: it needs `subscription.read`. */
  mayRead: boolean;
  pending: NonNullable<Subscription['pending']> | null;
  cancellation: CancellationDecision | undefined;
  buying: boolean;
  acting: boolean;
  error: unknown;
  onBuy: () => void;
  onTakeFreePeriod: () => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onWithdraw: () => void;
  onCancel: () => void;
}) {
  const version = offer.version;
  const [confirming, setConfirming] = useState(false);

  return (
    <li
      data-offer={offer.id}
      data-standing={standing}
      className="rounded-card border border-line bg-surface p-4 shadow-raise md:flex md:flex-wrap md:items-center md:gap-4"
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

          {/* Price, period **and commitment** — §7's first row asks for all
              three, and how long somebody agrees to stay is not derivable from
              how often they pay (non-negotiable #23). The terms are the
              version's own, and the same values the subscription snapshots
              when it is taken out (§13.1). */}
          <span data-testid="offer-commitment" className="text-xs text-subtle">
            {t("Commitment")}:{' '}
            {version.terms.commitment_months === 0
              ? t("no commitment")
              : t(version.terms.commitment_months === 1 ? "{count} month" : "{count} months", {
                  count: version.terms.commitment_months,
                })}
          </span>

          <div className="mt-2 w-full space-y-2 md:mt-0 md:w-auto md:flex-none">
            <div className="flex flex-wrap items-center gap-2">
              {standing === 'CURRENT' && (
                <span data-testid="current-plan" className="text-xs font-medium text-muted">
                  {t("Your current plan")}</span>
              )}

              {standing === 'BUY' && mayBuySeat && (
                <Button type="button" pending={buying} onClick={onBuy} data-testid="buy-seat">
                  {t("Buy for yourself")}</Button>
              )}

              {standing === 'FREE_PERIOD' && mayBuySeat && (
                // Its own door, and not the checkout: nothing is outstanding,
                // so no order, no invoice and no payment are raised — a €0
                // invoice would be a permanent hole in a gapless legal series
                // (§6.3). `openCheckoutSession` refuses this offer outright.
                <Button
                  type="button"
                  pending={acting}
                  onClick={onTakeFreePeriod}
                  data-testid="start-freemium"
                >
                  {t("Start the free period")}</Button>
              )}

              {standing === 'UP' && mayChangeSeat && (
                <Button type="button" pending={acting} onClick={onMoveUp} data-testid="move-up">
                  {t("Move to this plan")}</Button>
              )}

              {standing === 'DOWN' && mayChangeSeat && (
                // A **different operation**, and that is the point of having
                // compared the ranks: this one records an intention the
                // renewal applies, and the immediate one would be refused
                // (`NOT_A_DOWNGRADE`) or, worse, would take away service the
                // customer has paid for.
                <Button type="button" pending={acting} onClick={onMoveDown} data-testid="move-down">
                  {t("Move down to this plan")}</Button>
              )}

              {standing === 'PENDING' && pending !== null && (
                <span data-testid="pending-change" className="text-xs text-muted">
                  {t("You will move to the {plan} plan on {date}.", {
                    plan: pending.plan.name,
                    date: new Date(pending.effective_at).toLocaleDateString(currentLocale()),
                  })}
                </span>
              )}

              {standing === 'PENDING' && mayChangeSeat && (
                // Never optional (§4.2): a future change nobody can withdraw
                // is a cancellation in disguise.
                <Button
                  type="button"
                  variant="secondary"
                  pending={acting}
                  onClick={onWithdraw}
                  data-testid="cancel-pending-change"
                >
                  {t("Cancel the change")}</Button>
              )}

              {standing === 'CURRENT' && mayChangeSeat && (
                confirming ? (
                  <>
                    <Button
                      type="button"
                      variant="danger"
                      pending={acting}
                      data-testid="cancel-seat"
                      onClick={() => {
                        setConfirming(false);
                        onCancel();
                      }}
                    >
                      {t("Cancel the subscription")}</Button>
                    <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                      {t("Keep it")}</Button>
                  </>
                ) : (
                  <Button
                    type="button"
                    variant="danger"
                    data-testid="confirm-cancel-seat"
                    onClick={() => setConfirming(true)}
                  >
                    {t("Cancel…")}</Button>
                )
              )}
            </div>

            {/* The free period is gone, said before anything is clicked
                (§6.4) — and, for somebody on the plan above it, what leaving
                downwards actually is (§6.2). */}
            {standing === 'SPENT' && (
              <div data-testid="freemium-spent" className="space-y-0.5 text-xs text-muted">
                <p className="font-medium">
                  {t("You have already had the free period for this product")}</p>
                <p>{t("It is given once and once only, whether it is still running, was cancelled or ran out long ago.")}</p>
                {mayChangeSeat && (
                  <p data-testid="leaving-is-cancelling">
                    {t("So there is nothing below your plan to move to: leaving it is a cancellation, which your own plan offers.")}</p>
                )}
              </div>
            )}

            {error !== null && error !== undefined && <ErrorSurface error={error} />}

            {/* What the move would do, from the server and before the click.
                Asked for the two rows that are a move, and only where the read
                is permitted. */}
            {(standing === 'UP' || standing === 'DOWN') && mayRead && (
              <WhatItWouldDo offerId={offer.id} />
            )}

            {/* The decision, with its rule and its chargeable months — §7's
                last row. The server's preview of leaving, so nobody discovers
                a buy-out afterwards. */}
            {standing === 'CURRENT' && mayChangeSeat && cancellation !== undefined && (
              <CancellationOutcome decision={cancellation} label={t("If you cancelled now")} />
            )}
          </div>
        </>
      )}
    </li>
  );
}

/**
 * What moving to this offer would cost, as the server answered it (spec §7).
 *
 * One line, because it sits in a price list: *immediate, this much today* or
 * *on that date, at the end of the period you have paid for*. Every figure and
 * the date are read from the decision — the credit, the charge and the net come
 * back already worked out, because "never add two amounts in the frontend;
 * every total on screen is the server's" (§4, §25), and because the same
 * calculation answers the act, so the figure shown is the figure charged.
 *
 * A **query** and not a mutation: it writes nothing, raises no document and
 * allocates no number, which is what lets a catalogue ask it once per row.
 *
 * Its refusals are answers too, and they are rendered rather than swallowed:
 * `COMMITMENT_OUTLASTS_TERM`, and `FREEMIUM_ALREADY_USED` where a row somehow
 * reaches here with a spent free period. That is the §7 promise — the reason is
 * on screen before the button rather than after it.
 */
function WhatItWouldDo({ offerId }: { offerId: string }) {
  // `true`: the caller's own seat. Without the flag this previews the
  // organisation's subscription, which the customer does not have.
  const preview = usePreviewOfferChange(offerId, true, true);

  if (preview.isPending) {
    return <SkeletonRows rows={1} />;
  }

  if (preview.error !== null) {
    return <ErrorSurface error={preview.error} />;
  }

  const decision = preview.data.if_changed_now;
  const net = { minor_units: Math.abs(decision.net_minor_units), currency: decision.currency };

  return (
    <p
      data-testid="what-it-would-do"
      data-rule={decision.rule_id}
      data-direction={decision.direction}
      data-effect={decision.effect}
      className="text-xs text-muted"
    >
      {!decision.accepted
        ? t("This change cannot be priced on these terms.")
        : decision.effect === 'AT_PERIOD_END'
          ? decision.effective_at === null
            ? t("It takes effect at the end of the period you have paid for.")
            : t("On {date}, at the end of the period you have paid for.", {
                date: new Date(decision.effective_at).toLocaleDateString(currentLocale()),
              })
          : decision.net_minor_units < 0
            ? tx("Immediate — {amount} back to you.", { amount: <Amount money={net} /> })
            : tx("Immediate — {amount} to pay today.", { amount: <Amount money={net} /> })}
    </p>
  );
}

/**
 * What a change of plan or a departure just did, held for exactly as long as
 * the render that reports it.
 *
 * `invoiceId` null is "nothing was outstanding" — which raises no document,
 * because a €0 invoice would be a permanent hole in a gapless legal series.
 * `payment` null is "the server has not answered yet", never "there is
 * nothing to pay": the two are different and the screen must not merge them.
 */
type Outcome = {
  heading: string;
  what: string;
  credited: ChangeDecision | null;
  invoiceId: string | null;
  payment: StartedPayment | null;
};