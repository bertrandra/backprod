import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { useOffers } from '@/queries/catalogue';
import {
  useCancelScheduledChange,
  useCancelSubscription,
  useChangeOffer,
  useEntitlements,
  usePreviewOfferChange,
  useResumeSubscription,
  useSchedule,
  useScheduleOfferChange,
  useSubscription,
  type Entitlement,
  type Subscription,
} from '@/queries/subscription';
import { useOrganisation } from '@/queries/organisation';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { notice, pill, type Tone } from '@/ui/tone';
import { When } from '@/ui/When';
import { PageHeader } from '@/ui/Page';
import { Whose } from '@/ui/Whose';

import { CancellationOutcome, ChangeOutcome } from './decisions';
import { SubscriptionPeople } from './SubscriptionPeople';
import { currentLocale, t } from '@/i18n';
import { billingPeriod } from '@/ui/period';

/**
 * `tenant.subscription` — and the distinction §13.1 exists to protect.
 *
 * **Periodicity is not commitment** (non-negotiable #23). Being billed monthly
 * and having agreed to stay twelve months are different facts about the same
 * subscription, and a screen that showed one number would answer neither
 * question. Both are shown, labelled, and never added together.
 *
 * **Cancelling shows the decision before it is taken.** `showSchedule` answers
 * with `if_cancelled_now` — the backend's own preview of what leaving today
 * would mean, including how many months of commitment would still be owed. That
 * preview is rendered before the button, so nobody discovers the charge
 * afterwards.
 *
 * `immediately` is a *request*: the contract says "the policy still decides;
 * asking does not make it so", and the screen says the same rather than
 * promising an immediate end it cannot deliver.
 *
 * **Two subscribers, shown apart** (§13.1, 2026-09-18). The organisation's
 * subscription entitles everyone and is the administrator's to change or
 * cancel — offered with `billing.manage`, the organisation's view, as the
 * catalogue offers the purchase. A person's own seat is theirs: shown to its
 * holder beside the organisation's, and given up by them alone, with the
 * `seat` flag and never an id.
 */
export function SubscriptionScreen() {
  const { data: session } = useSession();
  // Which organisation this answer is about (2026-09-26). `tenant.read` is
  // every member's, and the read is gated on it rather than assumed: a screen
  // that asked anyway would spend a refused request per visit.
  const organisation = useOrganisation(can(session, 'tenant.read'));
  const subscription = useSubscription();
  const schedule = useSchedule();
  const entitlements = useEntitlements();
  const offers = useOffers();
  const changeOffer = useChangeOffer();
  const scheduleChange = useScheduleOfferChange();
  const cancelScheduled = useCancelScheduledChange();
  const cancel = useCancelSubscription();
  const resume = useResumeSubscription();

  const [immediately, setImmediately] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [offerId, setOfferId] = useState('');

  const mayManage = can(session, 'subscription.manage');

  // **Whoever holds it may act on it** (2026-10-01), and this is not a
  // loosening. The controls sat behind `subscription.manage` because the
  // subscription on this screen was the *organisation's*: changing or
  // cancelling it bound everybody, so it answered to a role. A seat binds one
  // person and is bought with their own card, and the panel that gave them
  // those controls — `YourSeat` — went when the two panels became one.
  //
  // Without this a seat is a trap: the holder can take one out and not give it
  // up, which is the thing §13.1 names as not being a subscription at all.
  // Courtesy, as every gate here is — the API decides, and `cancel` with no id
  // can only mean the caller's own.
  const mayAct = mayManage || (subscription.data?.coverage?.own ?? false);

  if (subscription.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (subscription.error !== null) {
    return <ErrorSurface error={subscription.error} onRetry={() => void subscription.refetch()} />;
  }

  // One subscription a person can have on a product, and it is theirs:
  // `subscription` — the organisation's — went with `subscriber_kind` on
  // 2026-10-01, because nothing has been able to create one since ADR-055.
  const seat = subscription.data.seat ?? null;
  const current = seat;

  // The banner of spec §2.2, for whichever contract is suspended — built here
  // rather than inside either branch below, because a seat can be in arrears
  // while the organisation has no subscription at all, and that is precisely
  // where the holder needs telling: without it the screen shows them an empty
  // state and invites them to buy what they already own.
  const arrears = (
    <>
      {seat !== null && seat.status === 'PAST_DUE' && <PaymentFailed subscription={seat} />}
      {/* A second banner stood here for the organisation's subscription,
          because a seat and the organisation's could be in arrears at once.
          There is one contract (2026-10-01), so there is one banner. */}
    </>
  );

  // `YourSeat` stood here and rendered the caller's seat **beside** the
  // organisation's subscription — two contracts, two panels. With one
  // subscription it and the section below are the same thing twice, which is
  // what every duplicated status, people-count and arrears banner was.

  if (current === null) {
    // No subscription — but possibly something the platform gave
    // (docs/tenant-roots.md §2.8), which is not a subscription to cancel and
    // must not read as "nothing".
    const provided = (entitlements.data ?? []).filter((entitlement) => entitlement.source === 'GRANT');

    // `withheld` stood here — "your organisation has one and you are not on
    // it" — and the organisation cannot have one any more. What replaced the
    // fact it carried is `coverage` below, which says what *does* cover you
    // and is about the person rather than about the company.

    // **Nothing is said about an organisation's subscription to somebody who
    // holds a seat** (2026-10-01). This used to read "No subscription for the
    // organisation — your seat is yours alone. An offer from the catalogue,
    // bought for the organisation, starts one for everyone", and that purchase
    // has not existed since ADR-055: the tenant surface sells seats only,
    // `Sales::order()` has no argument for the other sale, and ADR-056 removed
    // the endpoint that started one. So the screen named an absence nobody can
    // fill and told somebody how to fill it.
    //
    // `nothingToSay` guarded against naming that absence to a seat holder.
    // With one kind of subscription the holder never reaches this branch at
    // all — `current` is their seat — so the guard has nothing left to do.

    // **Covered by a colleague's subscription** (2026-10-01). The commonest
    // case on this screen, and the one it had no words for: somebody added to
    // a colleague's seat holds none of their own, their organisation has
    // none, and so every branch above answered "nothing is subscribed, an
    // offer from the catalogue starts one" — to a person working inside a
    // subscription and occupying a place their colleague pays for.
    //
    // No price, no offer, no terms: those belong to whoever manages it
    // (ADR-053), and the server does not send them.
    const covered = subscription.data.coverage;
    const byAColleague = covered !== null && !covered.own;

    return (
      <div className="max-w-3xl space-y-6">
        <h1 className="text-2xl font-semibold">{t("Subscription")}</h1>
        <Held organisation={organisation.data?.name ?? null} session={session ?? null} />
        {arrears}

        {byAColleague && covered !== null && (
          <section
            data-testid="covered-by-a-colleague"
            data-status={covered.status}
            className="space-y-2 rounded-card border border-line bg-surface p-4 shadow-raise"
          >
            <h2 className="text-base font-semibold">{t("A colleague's subscription covers you")}</h2>
            <p className="text-sm text-muted">
              {covered.status === 'PAST_DUE'
                ? t("It has an unpaid invoice, so this product is suspended until it is settled. Whoever holds it can pay it.")
                : t("You work on their subscription and take one of its places. Whoever holds it can take it back, and can tell you what it covers.")}
            </p>
            {covered.current_period_end !== null && (
              <p className="text-xs text-subtle">
                {t("Runs until")} <When at={covered.current_period_end} />
              </p>
            )}
          </section>
        )}

        {!byAColleague && (
          <EmptyState
            title={t("No subscription")}
            description={t("Nothing is subscribed in this product yet. An offer from the catalogue starts one.")}
          />
        )}
        {provided.length > 0 && <ProvidedByThePlatform entitlements={provided} />}
      </div>
    );
  }

  const terms = current.terms;

  // A move down that has not happened yet (spec §4). Read, never derived: the
  // date is the server's and so is the plan it names.
  const pending = current.pending ?? null;

  // Which of the two acts the chosen offer is, by **rank** and never by a
  // plan's name (§13, and `gate:plans` in PHP). Up is immediate; down waits
  // for the end of the period the customer has paid for.
  const chosen = (offers.data ?? []).find((offer) => offer.id === offerId) ?? null;
  const goesDown = chosen !== null && chosen.plan.rank < current.offer.plan.rank;
  const move = goesDown ? scheduleChange : changeOffer;

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Subscription")}
        description={<>{current.offer.name} · {current.offer.plan.name}{seat !== null && ' — the organisation\'s'}</>}
      />

      <Held organisation={organisation.data?.name ?? null} session={session ?? null} />

      {arrears}

      <section className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <span
            data-testid="subscription-status"
            data-status={current.status}
            className={pill(statusTone(current.status))}
          >
            {current.status}
          </span>
          {current.cancel_at_period_end && (
            <span data-testid="cancelling" className="text-xs text-subtle">
              {t("ends at the period boundary")}</span>
          )}
        </div>

        {/* The two facts, side by side and never merged. */}
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wide text-subtle">{t("Billed")}</dt>
            <dd data-testid="periodicity">
              {billingPeriod(current.offer.version.billing_period)} ·{' '}
              <Amount money={current.offer.version.price} />
            </dd>
            <dd className="text-xs text-subtle">
              {t("period")}{' '}{new Date(current.current_period_start).toLocaleDateString(currentLocale())} —{' '}
              {current.current_period_end === null
                ? 'open'
                : new Date(current.current_period_end).toLocaleDateString(currentLocale())}
            </dd>
          </div>

          <div>
            <dt className="text-xs uppercase tracking-wide text-subtle">{t("Commitment")}</dt>
            <dd data-testid="commitment">
              {/* A different question from how often it is billed. */}
              {terms === null || terms === undefined ? (
                <span className="text-subtle">{t("None recorded")}</span>
              ) : (
                <TermsSummary terms={terms} />
              )}
            </dd>
          </div>
        </dl>
      </section>

      {pending !== null && (
        <PendingChange
          pending={pending}
          mayManage={mayAct}
          pendingRequest={cancelScheduled.isPending}
          error={cancelScheduled.error}
          onCancel={() => cancelScheduled.mutate()}
        />
      )}

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">{t("Entitlements")}</h2>

        {entitlements.isPending ? (
          <SkeletonRows rows={3} />
        ) : entitlements.error !== null ? (
          <ErrorSurface error={entitlements.error} onRetry={() => void entitlements.refetch()} />
        ) : entitlements.data.length === 0 ? (
          <EmptyState
            title={t("No entitlements")}
            description={t("This offer grants nothing yet, which is a configuration answer rather than an error.")}
          />
        ) : (
          <ul className="space-y-1 text-sm">
            {entitlements.data.map((entitlement) => (
              <li
                key={entitlement.feature}
                data-entitlement={entitlement.feature}
                className="flex flex-wrap gap-2"
              >
                <span className="min-w-0 flex-1">{entitlement.name}</span>
                <span className="text-muted">
                  {/* `limit` null means two different things and `unlimited`
                      says which — so both are read rather than one guessed. */}
                  {entitlement.kind === 'BOOLEAN'
                    ? t("included")
                    : entitlement.unlimited
                      ? t("unlimited")
                      : `${String(entitlement.limit ?? 0)}${entitlement.unit === null ? '' : ` ${entitlement.unit}`}`}
                </span>
                <span className="text-xs text-subtle">
                  {entitlement.source === 'GRANT'
                    ? (entitlement.valid_until === null ? t("provided by the platform") : t("provided by the platform until {until}", { until: entitlement.valid_until.slice(0, 10) }))
                    : t("from {source}", { source: entitlement.source })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* Who the organisation's subscription covers (2026-09-19): read by
          anybody, managed by whoever activated it. */}
      <SubscriptionPeople seat={false} />

      {mayAct && (
        <>
          <section className="space-y-3 border-t border-line pt-6">
            <h2 className="text-xl font-semibold">{t("Change offer")}</h2>

            {move.error !== null && <ErrorSurface error={move.error} />}

            <div className="flex max-w-md flex-wrap items-end gap-2">
              <Field id="offer" label={t("Offer")}>
                <select
                  id="offer"
                  className={inputClass()}
                  value={offerId}
                  onChange={(event) => setOfferId(event.target.value)}
                >
                  <option value="">{t("Choose an offer")}</option>
                  {(offers.data ?? [])
                    .filter((offer) => offer.id !== current.offer.id)
                    .map((offer) => (
                      <option key={offer.id} value={offer.id}>
                        {offer.name}
                      </option>
                    ))}
                </select>
              </Field>
              <Button
                type="button"
                pending={move.isPending}
                disabled={offerId === ''}
                data-testid="change-offer"
                data-deferred={goesDown ? 'true' : 'false'}
                onClick={() => move.mutate(offerId, { onSuccess: () => setOfferId('') })}
              >
                {goesDown ? t("Move down to this plan") : t("Change")}</Button>
            </div>

            {/* What the move actually did, from the server's own answer — the
                same decision the preview showed, so the two cannot disagree. */}
            {changeOffer.data?.change !== undefined && (
              <ChangeOutcome decision={changeOffer.data.change} label={t("What the change did")} />
            )}

            {/* Said before the click, not discovered after it — and said by
                the **server**, which is the whole of spec §7. The credit, the
                amount payable today and the date all come from the same
                calculation the button then runs, so nothing here can quote a
                figure that will not be charged. */}
            {chosen !== null && <ChangePreview offerId={chosen.id} />}
          </section>

          <section className="space-y-3 border-t border-line pt-6">
            <h2 className="text-xl font-semibold">
              {current.cancel_at_period_end ? t("Cancellation") : t("Cancel")}
            </h2>

            {current.cancel_at_period_end ? (
              <div className="space-y-3">
                <p className="text-sm">
                  {t("This subscription ends")}{current.cancel_effective_at === null
                    ? t(" at the end of the current period")
                    : ` on ${new Date(current.cancel_effective_at).toLocaleDateString(currentLocale())}`}
                  .
                </p>
                {resume.error !== null && <ErrorSurface error={resume.error} />}
                <Button
                  type="button"
                  pending={resume.isPending}
                  onClick={() => resume.mutate()}
                >
                  {t("Resume it")}</Button>
              </div>
            ) : (
              <div className="space-y-3">
                {/* The preview, before the button. The backend computes it, so
                    the number here is the number that will be charged. */}
                {schedule.data?.if_cancelled_now !== undefined && (
                  <CancellationOutcome decision={schedule.data.if_cancelled_now} label={t("If you cancelled now")} />
                )}

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-5"
                    checked={immediately}
                    onChange={(event) => setImmediately(event.target.checked)}
                  />
                  {t("Ask to end immediately")}</label>
                <p className="text-xs text-muted">
                  {t("Asking does not make it so — the cancellation policy decides, and the answer below is what it decided.")}</p>

                {cancel.error !== null && <ErrorSurface error={cancel.error} />}

                {confirming ? (
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      variant="danger"
                      pending={cancel.isPending}
                      onClick={() =>
                        cancel.mutate(
                          { immediately },
                          { onSettled: () => setConfirming(false) },
                        )
                      }
                    >
                      {t("Cancel the subscription")}</Button>
                    <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                      {t("Keep it")}</Button>
                  </div>
                ) : (
                  <Button type="button" variant="danger" onClick={() => setConfirming(true)}>
                    {t("Cancel…")}</Button>
                )}
              </div>
            )}

            {cancel.data?.cancellation !== undefined && cancel.data.cancellation !== null && (
              <CancellationOutcome decision={cancel.data.cancellation} label={t("What the policy decided")} />
            )}
          </section>
        </>
      )}
    </div>
  );
}

/**
 * Whose answer this is: the person asking, and the organisation they are
 * asking in (2026-09-26).
 *
 * Both, and neither is obvious from the rest of the screen. A seat is bought
 * by one person and cancelled by them alone, so "whose seat is this?" has an
 * answer and the screen never gave it; and somebody who belongs to two
 * organisations is one switcher click from reading the other one's
 * subscription with nothing on the page to say so.
 *
 * Read rather than derived: the name is the session's and the organisation is
 * the server's answer to `/tenants/current`. Absent while either is still
 * being asked, and absent rather than guessed if the organisation read is
 * refused — a name invented here would be a second answer to a question that
 * already has one.
 */
function Held({
  organisation,
  session,
}: {
  organisation: string | null;
  session: { displayName: string | null; email: string | null } | null;
}) {
  return (
    <Whose
      name={session?.displayName ?? session?.email ?? null}
      email={session?.email ?? null}
      organisation={organisation}
      testId="subscription-whose"
      className="text-xs text-muted"
    />
  );
}

function PendingChange({
  pending,
  mayManage,
  pendingRequest,
  error,
  onCancel,
}: {
  pending: NonNullable<Subscription['pending']>;
  mayManage: boolean;
  pendingRequest: boolean;
  error: unknown;
  onCancel: () => void;
}) {
  return (
    <section
      data-testid="pending-change"
      data-plan={pending.plan.code}
      className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise"
    >
      <p className="text-sm font-medium">
        {t("You will move to the {plan} plan on {date}.", {
          plan: pending.plan.name,
          date: new Date(pending.effective_at).toLocaleDateString(currentLocale()),
        })}
      </p>
      <p className="text-xs text-muted">
        {t("Until then you keep the plan you are on, entire — it is paid for until that date.")}</p>

      {mayManage && (
        <div className="space-y-2">
          {error !== null && error !== undefined && <ErrorSurface error={error} />}
          <Button
            type="button"
            variant="secondary"
            pending={pendingRequest}
            data-testid="cancel-pending-change"
            onClick={onCancel}
          >
            {t("Cancel the change")}</Button>
        </div>
      )}
    </section>
  );
}

/**
 * What changing to an offer would do, as the server answered it (spec §7).
 *
 * Every figure is read, never derived. The credit is the unconsumed share of
 * what was collected, the charge is the new period, and the net is the
 * difference — **worked out server-side**, because "never add two amounts in
 * the frontend; every total on screen is the server's" (§4, §25). A component
 * that subtracted these two itself would be a second answer to a question that
 * already has one, and it would disagree the moment rounding did.
 *
 * It says the same thing for both directions, which is why it replaced a
 * sentence this screen used to compose from `current_period_end`: a move down
 * costs nothing and takes effect on a date the server names, and deriving that
 * date here was one local calculation too many.
 *
 * Nothing about it is a button. Asking costs nothing and writes nothing, so it
 * renders as soon as an offer is chosen.
 */
function ChangePreview({ offerId }: { offerId: string }) {
  const preview = usePreviewOfferChange(offerId);

  if (preview.isPending) {
    return <SkeletonRows rows={2} />;
  }

  if (preview.error !== null) {
    return <ErrorSurface error={preview.error} />;
  }

  return <ChangeOutcome decision={preview.data.if_changed_now} label={t("If you changed now")} />;
}

/**
 * The commitment, read from the terms the subscription carries.
 *
 * Read defensively: the terms object is the subscription's own record of what
 * was agreed, and a missing field means "not recorded" rather than zero. A zero
 * commitment and an unrecorded one are different answers.
 */
function TermsSummary({ terms }: { terms: Record<string, unknown> }) {
  const months = terms.commitment_months;
  const notice = terms.notice_days;

  return (
    <span>
      {typeof months === 'number' ? (
        <>
          {t(months === 1 ? "{count} month" : "{count} months", { count: months })}
        </>
      ) : (
        <span className="text-subtle">{t("not recorded")}</span>
      )}
      {typeof notice === 'number' && (
        <span className="text-xs text-subtle"> · {notice} {t("days notice")}</span>
      )}
    </span>
  );
}

function statusTone(status: string): Tone {
  switch (status) {
    case 'ACTIVE':
      return 'success';
    case 'CANCELLED':
      return 'neutral';
    // Not `warning`, which is "waiting on somebody or something" (`ui/tone`).
    // A suspension is not a wait: the product is shut, and the tone says which
    // of the two this is before the words are read.
    case 'PAST_DUE':
      return 'danger';
    default:
      return 'warning';
  }
}

/**
 * The red banner of spec §2.2 — *“Payment failed.”* — with the way to pay.
 *
 * Shown for whichever subscription is suspended, the organisation's or the
 * caller's own seat, and once for each: they are two contracts and either can
 * be in arrears on its own.
 *
 * **Everything in it is read, nothing is derived.** `past_due_since` and
 * `past_due_invoice_id` are the server's answers and there is no arithmetic
 * here: a screen that decided what "overdue" means from a date would disagree
 * with the server the moment a product changed its schedule, and the schedule
 * is the product's (spec §5.2). The status decides whether this appears, and
 * the status is the server's too.
 *
 * **It links to the invoice and never to a plan.** The refusal a suspended
 * customer meets is answered by paying, not by buying — so the one action
 * offered is the document. Nothing here is gated on a permission: the invoices
 * a member may reach are already narrowed to their own (`documentsOf()`), and
 * the invoice screen refuses regardless. Hiding the link would only hide the
 * remedy from the person who needs it.
 */
function PaymentFailed({ subscription }: { subscription: Subscription }) {
  const since = subscription.past_due_since;
  const invoiceId = subscription.past_due_invoice_id;
  // It took a `scope` and said "your seat" or "your organisation's
  // subscription", because both could be in arrears at once. One contract is
  // left (2026-10-01), so there is one thing to name.
  const whose = t("Your subscription");

  return (
    <section data-testid="past-due" className={notice('danger')}>
      <h2 className="text-base font-semibold">{t("Payment failed")}</h2>
      <p className="mt-1">
        {since === null
          ? t("{whose} is suspended because an invoice for it has not been paid.", { whose })
          : t("{whose} has been suspended since {since} because an invoice for it has not been paid.", {
              whose,
              since: new Date(since).toLocaleDateString(currentLocale()),
            })}
      </p>
      <p className="mt-1">
        {t("Your invoices and payments are still available, so you can settle it from here. Access returns as soon as the payment is confirmed.")}
      </p>
      {invoiceId !== null && (
        <p className="mt-2 text-xs">
          <Link
            to="/invoices/$invoiceId"
            params={{ invoiceId }}
            className="underline decoration-dotted"
            data-testid="past-due-invoice"
          >
            {t("Pay the invoice")}</Link>
        </p>
      )}
    </section>
  );
}

/**
 * What the platform gave, shown as what it is: not a subscription — nothing
 * renews, nothing is invoiced, nothing here can be cancelled — but what
 * this organisation may use on the product, until the date it was given
 * for. A grant is renewed by a person at the platform.
 */
function ProvidedByThePlatform({ entitlements }: { entitlements: readonly Entitlement[] }) {
  const until = entitlements.map((entitlement) => entitlement.valid_until).find((moment) => moment !== null) ?? null;

  return (
    <section data-testid="provided-by-platform" className="space-y-3 border-t border-line pt-6">
      <h2 className="text-xl font-semibold">{t("Provided by the platform")}</h2>
      <p className="text-sm text-muted">
        {until === null
          ? t("These are yours to use without a subscription, with no end date.")
          : t("These are yours to use without a subscription, until {value}.", { value: until.slice(0, 10) })}
      </p>
      <ul className="space-y-1 text-sm">
        {entitlements.map((entitlement) => (
          <li key={entitlement.feature} data-entitlement={entitlement.feature} className="flex flex-wrap gap-2">
            <span className="min-w-0 flex-1">{entitlement.name}</span>
            <span className="text-muted">
              {entitlement.kind === 'BOOLEAN'
                ? t("included")
                : entitlement.unlimited
                  ? t("unlimited")
                  : `${String(entitlement.limit ?? 0)}${entitlement.unit === null ? '' : ` ${entitlement.unit}`}`}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
