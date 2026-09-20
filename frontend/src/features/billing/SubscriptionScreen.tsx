import { useState } from 'react';

import { can } from '@/app/access/access';
import { useOffers } from '@/queries/catalogue';
import {
  useCancelSubscription,
  useChangeOffer,
  useEntitlements,
  useResumeSubscription,
  useSchedule,
  useSubscription,
  type CancellationDecision,
  type Entitlement,
  type Subscription,
} from '@/queries/subscription';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';

import { SubscriptionPeople } from './SubscriptionPeople';
import { currentLocale, t } from '@/i18n';
import { tx } from '@/i18n/react';

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
  const subscription = useSubscription();
  const schedule = useSchedule();
  const entitlements = useEntitlements();
  const offers = useOffers();
  const changeOffer = useChangeOffer();
  const cancel = useCancelSubscription();
  const resume = useResumeSubscription();

  const [immediately, setImmediately] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [offerId, setOfferId] = useState('');

  const mayManage = can(session, 'subscription.manage');
  // The organisation's subscription binds everyone; acting on it is offered
  // with the organisation's view. Courtesy, as every gate here is — the API
  // is the authority.
  const mayManageOrganisation = mayManage && can(session, 'billing.manage');

  if (subscription.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (subscription.error !== null) {
    return <ErrorSurface error={subscription.error} onRetry={() => void subscription.refetch()} />;
  }

  const current = subscription.data.subscription;
  const seat = subscription.data.seat ?? null;

  const ownSeat = seat === null ? null : (
    <YourSeat
      seat={seat}
      mayManage={mayManage}
      pending={cancel.isPending}
      error={cancel.error}
      onCancel={() => cancel.mutate({ seat: true })}
    />
  );

  if (current === null) {
    // No subscription — but possibly something the platform gave
    // (docs/tenant-roots.md §2.8), which is not a subscription to cancel and
    // must not read as "nothing".
    const provided = (entitlements.data ?? []).filter((entitlement) => entitlement.source === 'GRANT');

    return (
      <div className="max-w-3xl space-y-6">
        <h1 className="text-2xl font-semibold">{t("Subscription")}</h1>
        {ownSeat}
        <EmptyState
          title={seat === null ? t("No subscription") : t("No subscription for the organisation")}
          description={
            seat === null
              ? t("Nothing is subscribed in this product yet. An offer from the catalogue starts one.")
              : t("Your seat is yours alone. An offer from the catalogue, bought for the organisation, starts one for everyone.")
          }
        />
        {provided.length > 0 && <ProvidedByThePlatform entitlements={provided} />}
      </div>
    );
  }

  const terms = current.terms;

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={t("Subscription")}
        description={<>{current.offer.name} · {current.offer.plan.name}{seat !== null && ' — the organisation\'s'}</>}
      />

      {ownSeat}

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
              {current.offer.version.billing_period.toLowerCase()} ·{' '}
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

      {mayManageOrganisation && (
        <>
          <section className="space-y-3 border-t border-line pt-6">
            <h2 className="text-xl font-semibold">{t("Change offer")}</h2>

            {changeOffer.error !== null && <ErrorSurface error={changeOffer.error} />}

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
                pending={changeOffer.isPending}
                disabled={offerId === ''}
                onClick={() => changeOffer.mutate(offerId, { onSuccess: () => setOfferId('') })}
              >
                {t("Change")}</Button>
            </div>
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
                  <Decision decision={schedule.data.if_cancelled_now} label={t("If you cancelled now")} />
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
              <Decision decision={cancel.data.cancellation} label={t("What the policy decided")} />
            )}
          </section>
        </>
      )}
    </div>
  );
}

/**
 * The caller's own seat (§13.1): what it is, when it is paid to, and the one
 * act its holder may take on it. No offer change — a seat is exchanged by
 * ending one and buying another — and the preview of leaving is the same
 * policy's, which the decision reports once it is asked.
 */
function YourSeat({
  seat,
  mayManage,
  pending,
  error,
  onCancel,
}: {
  seat: Subscription;
  mayManage: boolean;
  pending: boolean;
  error: unknown;
  onCancel: () => void;
}) {
  const [confirming, setConfirming] = useState(false);

  return (
    <section data-testid="your-seat" data-status={seat.status} className="space-y-3 rounded-card border border-line bg-surface p-4 shadow-raise">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-xl font-semibold">{t("Your seat")}</h2>
        <span className={pill(statusTone(seat.status))}>{seat.status}</span>
        {seat.cancel_at_period_end && (
          <span data-testid="seat-cancelling" className="text-xs text-subtle">
            {t("ends")}{seat.cancel_effective_at === null
              ? t(" at the period boundary")
              : ` on ${new Date(seat.cancel_effective_at).toLocaleDateString(currentLocale())}`}
          </span>
        )}
      </div>

      <p className="text-sm">
        {tx("{offer} · {plan} — yours alone, paid with your own card.", { offer: <span className="font-medium">{seat.offer.name}</span>, plan: seat.offer.plan.name })}
      </p>

      <dl className="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Billed")}</dt>
          <dd>
            {seat.offer.version.billing_period.toLowerCase()} · <Amount money={seat.offer.version.price} />
          </dd>
          <dd className="text-xs text-subtle">
            {t("period")}{' '}{new Date(seat.current_period_start).toLocaleDateString(currentLocale())} —{' '}
            {seat.current_period_end === null ? 'open' : new Date(seat.current_period_end).toLocaleDateString(currentLocale())}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-subtle">{t("Commitment")}</dt>
          <dd>
            {seat.terms === null || seat.terms === undefined ? (
              <span className="text-subtle">{t("None recorded")}</span>
            ) : (
              <TermsSummary terms={seat.terms} />
            )}
          </dd>
        </div>
      </dl>

      {/* The people the seat covers (2026-09-19): its holder owns it. */}
      <SubscriptionPeople seat />

      {mayManage && !seat.cancel_at_period_end && (
        <div className="space-y-2">
          {error !== null && error !== undefined && <ErrorSurface error={error} />}
          {confirming ? (
            <div className="flex flex-wrap gap-2">
              <Button type="button" variant="danger" pending={pending} onClick={onCancel} data-testid="cancel-seat">
                {t("Give up your seat")}</Button>
              <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                {t("Keep it")}</Button>
            </div>
          ) : (
            <Button type="button" variant="danger" onClick={() => setConfirming(true)}>
              {t("Give up your seat…")}</Button>
          )}
          <p className="text-xs text-muted">
            {t("The cancellation policy decides when it ends; what it decided is shown once asked.")}</p>
        </div>
      )}
    </section>
  );
}

/**
 * A cancellation decision, in full.
 *
 * Not `accepted: true`. What somebody needs is *when* it takes effect, what it
 * costs, and which rule said so — the rule's id travels with the decision
 * precisely so it can be quoted in a support conversation.
 */
function Decision({ decision, label }: { decision: CancellationDecision; label: string }) {
  return (
    <div
      data-testid="cancellation-decision"
      data-effect={decision.effect}
      data-rule={decision.rule_id}
      className="space-y-1 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <p className="font-medium">{label}</p>

      <p data-testid="decision-effect">
        {decision.effect === 'REFUSED'
          ? t("It would be refused.")
          : decision.effective_at === null
            ? t("Effect: {value}", { value: decision.effect.toLowerCase().replace(/_/g, ' ') })
            : t("Takes effect {value} ({value_})", { value: new Date(decision.effective_at).toLocaleDateString(currentLocale()), value_: decision.effect
                .toLowerCase()
                .replace(/_/g, ' ') })}
      </p>

      {decision.chargeable_months > 0 && (
        // Counted from the end of the period already paid for, not from today —
        // which is why this is the server's number and not a subtraction here.
        <p data-testid="chargeable-months">
          {t(
            decision.chargeable_months === 1
              ? "{count} month of commitment would still be owed."
              : "{count} months of commitment would still be owed.",
            { count: decision.chargeable_months },
          )}
        </p>
      )}

      {decision.reasons.length > 0 && (
        <ul className="list-inside list-disc text-xs text-muted">
          {decision.reasons.map((reason) => (
            <li key={reason}>{reason}</li>
          ))}
        </ul>
      )}

      <p className="text-xs text-subtle">
        {t("Rule")}{' '}<code>{decision.rule_id}</code>
      </p>
    </div>
  );
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
    default:
      return 'warning';
  }
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
