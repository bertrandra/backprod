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
} from '@/queries/subscription';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button, Field, inputClass } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';

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

  if (subscription.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (subscription.error !== null) {
    return <ErrorSurface error={subscription.error} onRetry={() => void subscription.refetch()} />;
  }

  const current = subscription.data.subscription;

  if (current === null) {
    return (
      <div className="max-w-3xl space-y-6">
        <h1 className="text-2xl font-semibold">Subscription</h1>
        <EmptyState
          title="No subscription"
          description="Nothing is subscribed in this product yet. An offer from the catalogue starts one."
        />
      </div>
    );
  }

  const terms = current.terms;

  return (
    <div className="max-w-3xl space-y-8">
      <PageHeader
        title={'Subscription'}
        description={<>{current.offer.name} · {current.offer.plan.name}</>}
      />

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
              ends at the period boundary
            </span>
          )}
        </div>

        {/* The two facts, side by side and never merged. */}
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wide text-subtle">Billed</dt>
            <dd data-testid="periodicity">
              {current.offer.version.billing_period.toLowerCase()} ·{' '}
              <Amount money={current.offer.version.price} />
            </dd>
            <dd className="text-xs text-subtle">
              period {new Date(current.current_period_start).toLocaleDateString()} —{' '}
              {current.current_period_end === null
                ? 'open'
                : new Date(current.current_period_end).toLocaleDateString()}
            </dd>
          </div>

          <div>
            <dt className="text-xs uppercase tracking-wide text-subtle">Commitment</dt>
            <dd data-testid="commitment">
              {/* A different question from how often it is billed. */}
              {terms === null || terms === undefined ? (
                <span className="text-subtle">None recorded</span>
              ) : (
                <TermsSummary terms={terms} />
              )}
            </dd>
          </div>
        </dl>
      </section>

      <section className="space-y-3 border-t border-line pt-6">
        <h2 className="text-xl font-semibold">Entitlements</h2>

        {entitlements.isPending ? (
          <SkeletonRows rows={3} />
        ) : entitlements.error !== null ? (
          <ErrorSurface error={entitlements.error} onRetry={() => void entitlements.refetch()} />
        ) : entitlements.data.length === 0 ? (
          <EmptyState
            title="No entitlements"
            description="This offer grants nothing yet, which is a configuration answer rather than an error."
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
                    ? 'included'
                    : entitlement.unlimited
                      ? 'unlimited'
                      : `${String(entitlement.limit ?? 0)}${entitlement.unit === null ? '' : ` ${entitlement.unit}`}`}
                </span>
                <span className="text-xs text-subtle">from {entitlement.source}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {mayManage && (
        <>
          <section className="space-y-3 border-t border-line pt-6">
            <h2 className="text-xl font-semibold">Change offer</h2>

            {changeOffer.error !== null && <ErrorSurface error={changeOffer.error} />}

            <div className="flex max-w-md flex-wrap items-end gap-2">
              <Field id="offer" label="Offer">
                <select
                  id="offer"
                  className={inputClass()}
                  value={offerId}
                  onChange={(event) => setOfferId(event.target.value)}
                >
                  <option value="">Choose an offer</option>
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
                Change
              </Button>
            </div>
          </section>

          <section className="space-y-3 border-t border-line pt-6">
            <h2 className="text-xl font-semibold">
              {current.cancel_at_period_end ? 'Cancellation' : 'Cancel'}
            </h2>

            {current.cancel_at_period_end ? (
              <div className="space-y-3">
                <p className="text-sm">
                  This subscription ends
                  {current.cancel_effective_at === null
                    ? ' at the end of the current period'
                    : ` on ${new Date(current.cancel_effective_at).toLocaleDateString()}`}
                  .
                </p>
                {resume.error !== null && <ErrorSurface error={resume.error} />}
                <Button
                  type="button"
                  pending={resume.isPending}
                  onClick={() => resume.mutate()}
                >
                  Resume it
                </Button>
              </div>
            ) : (
              <div className="space-y-3">
                {/* The preview, before the button. The backend computes it, so
                    the number here is the number that will be charged. */}
                {schedule.data?.if_cancelled_now !== undefined && (
                  <Decision decision={schedule.data.if_cancelled_now} label="If you cancelled now" />
                )}

                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-5"
                    checked={immediately}
                    onChange={(event) => setImmediately(event.target.checked)}
                  />
                  Ask to end immediately
                </label>
                <p className="text-xs text-muted">
                  Asking does not make it so — the cancellation policy decides, and the answer
                  below is what it decided.
                </p>

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
                      Cancel the subscription
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setConfirming(false)}>
                      Keep it
                    </Button>
                  </div>
                ) : (
                  <Button type="button" variant="danger" onClick={() => setConfirming(true)}>
                    Cancel…
                  </Button>
                )}
              </div>
            )}

            {cancel.data?.cancellation !== undefined && cancel.data.cancellation !== null && (
              <Decision decision={cancel.data.cancellation} label="What the policy decided" />
            )}
          </section>
        </>
      )}
    </div>
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
          ? 'It would be refused.'
          : decision.effective_at === null
            ? `Effect: ${decision.effect.toLowerCase().replace(/_/g, ' ')}`
            : `Takes effect ${new Date(decision.effective_at).toLocaleDateString()} (${decision.effect
                .toLowerCase()
                .replace(/_/g, ' ')})`}
      </p>

      {decision.chargeable_months > 0 && (
        // Counted from the end of the period already paid for, not from today —
        // which is why this is the server's number and not a subtraction here.
        <p data-testid="chargeable-months">
          {decision.chargeable_months} month{decision.chargeable_months === 1 ? '' : 's'} of
          commitment would still be owed.
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
        Rule <code>{decision.rule_id}</code>
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
          {months} month{months === 1 ? '' : 's'}
        </>
      ) : (
        <span className="text-subtle">not recorded</span>
      )}
      {typeof notice === 'number' && (
        <span className="text-xs text-subtle"> · {notice} days notice</span>
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
