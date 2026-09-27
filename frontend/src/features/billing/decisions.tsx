import { currentLocale, t } from '@/i18n';
import type { CancellationDecision, ChangeDecision } from '@/queries/subscription';
import { Amount } from '@/ui/Money';

/**
 * The two decisions a subscription answers with, rendered.
 *
 * Extracted from `SubscriptionScreen` when the catalogue gained the same
 * answers (2026-09-27, spec §7): one screen previews a change beside the
 * subscription, the other previews the same change on every row of a price
 * list, and both then show what the act decided. Two renderers would have been
 * two vocabularies for one document — and, since every sentence in them is a
 * translation key, two catalogues' worth of wording for one fact.
 *
 * Neither of them computes anything. Both are handed the server's own decision
 * object and read it: the credit, the charge, the net, the date, the rule and
 * the months owed all come back worked out, because "never add two amounts in
 * the frontend; every total on screen is the server's" (§4, §25) — and because
 * the preview and the act share one calculation server-side, so a figure shown
 * here is the figure charged.
 */

/**
 * A change decision, in full — the preview's and the act's, which are the same
 * object because they come from the same calculation.
 */
export function ChangeOutcome({ decision, label }: { decision: ChangeDecision; label: string }) {
  const money = (minorUnits: number) => ({ minor_units: minorUnits, currency: decision.currency });

  return (
    <div
      data-testid="change-decision"
      data-rule={decision.rule_id}
      data-direction={decision.direction}
      data-effect={decision.effect}
      className="max-w-md space-y-1 rounded-card border border-line bg-surface p-4 text-sm shadow-raise"
    >
      <p className="font-medium">{label}</p>

      {!decision.accepted ? (
        <p data-testid="change-refused">{t("This change cannot be priced on these terms.")}</p>
      ) : decision.effect === 'AT_PERIOD_END' ? (
        <p data-testid="change-effect">
          {decision.effective_at === null
            ? t("It takes effect at the end of the period you have paid for.")
            : t("It takes effect on {date}, at the end of the period you have paid for. Until then nothing changes, and nothing is charged.", { date: new Date(decision.effective_at).toLocaleDateString(currentLocale()) })}
        </p>
      ) : (
        <>
          <p data-testid="change-effect">{t("It takes effect immediately.")}</p>

          {/* The three amounts, apart. A single figure would hide which half
              of it is money coming back. */}
          <dl className="grid gap-1 sm:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wide text-subtle">{t("Credited")}</dt>
              <dd data-testid="change-credit">
                <Amount money={money(decision.credit_minor_units)} />
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-subtle">{t("New period")}</dt>
              <dd data-testid="change-charge">
                <Amount money={money(decision.charge_minor_units)} />
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-subtle">
                {decision.net_minor_units < 0 ? t("Back to you") : t("To pay today")}
              </dt>
              <dd data-testid="change-net" className="font-medium">
                <Amount money={money(Math.abs(decision.net_minor_units))} />
              </dd>
            </div>
          </dl>

          {decision.new_period_end !== null && (
            <p className="text-xs text-subtle">
              {t("The new period runs to {date}.", { date: new Date(decision.new_period_end).toLocaleDateString(currentLocale()) })}
            </p>
          )}
        </>
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
 * A cancellation decision, in full.
 *
 * Not `accepted: true`. What somebody needs is *when* it takes effect, what it
 * costs, and which rule said so — the rule's id travels with the decision
 * precisely so it can be quoted in a support conversation.
 */
export function CancellationOutcome({
  decision,
  label,
}: {
  decision: CancellationDecision;
  label: string;
}) {
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
