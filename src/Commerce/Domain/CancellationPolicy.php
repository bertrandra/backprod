<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * Whether a subscription may be cancelled now, and when it would end (§13.1).
 *
 * Two rules, cumulative, and neither follows from the other.
 *
 * **The service stays owed until the end of the paid period.** Cancelling on
 * the 2nd of a month already paid for takes nothing away before the month
 * ends. That was already M5's rule and it does not change.
 *
 * **Under commitment the request is refused or deferred — never silently
 * accepted and then ignored.** What happens depends on what was sold, and the
 * decision says which rule decided, because "no" without a reason is the
 * answer that generates a support ticket.
 */
final class CancellationPolicy
{
    /**
     * @param bool $immediately the caller asked to end it now rather than at
     *                          the end of what has been paid for
     */
    public function decide(
        Subscription $subscription,
        DateTimeImmutable $now,
        bool $immediately = false,
    ): CancellationDecision {
        $terms = $subscription->terms;
        $periodEnd = $subscription->currentPeriodEnd ?? $now;

        $underCommitment = $subscription->isUnderCommitmentAt($now);

        if (!$underCommitment) {
            // Free to go. The paid period is still owed, so the end of it is
            // when this takes effect — unless the caller asked for immediate,
            // which forfeits the remainder deliberately.
            return CancellationDecision::accepted(
                $immediately ? 'cancel.immediate' : 'cancel.at_period_end',
                $immediately ? CancellationDecision::IMMEDIATE : CancellationDecision::AT_PERIOD_END,
                $immediately ? $now : $periodEnd,
                $immediately
                    ? ['No commitment is in force.', 'Ending now forfeits the rest of the paid period.']
                    : [
                        'No commitment is in force.',
                        'The service is owed until the end of the period already paid for.',
                    ],
            );
        }

        $commitmentEnd = $subscription->commitmentEndsAt ?? $periodEnd;
        $termEnd = $subscription->termEndsAt ?? $commitmentEnd;

        // Buying out of a commitment, when that is something the offer sells.
        if ($immediately && $terms->earlyTermination !== SubscriptionTerms::FORBIDDEN) {
            $chargeable = $terms->earlyTermination === SubscriptionTerms::CHARGE_REMAINING
                ? self::monthsBetween($now, $commitmentEnd)
                : 0;

            return CancellationDecision::accepted(
                'cancel.early_termination',
                CancellationDecision::IMMEDIATE,
                $now,
                [
                    'A commitment is in force until ' . $commitmentEnd->format('Y-m-d') . '.',
                    $terms->earlyTermination === SubscriptionTerms::CHARGE_REMAINING
                        ? sprintf('Early termination is chargeable: %d month(s) remain.', $chargeable)
                        : 'Early termination is permitted at no charge.',
                ],
                $chargeable,
            );
        }

        return match ($terms->cancellationPolicy) {
            // Sold as cancellable at any time: the commitment binds the price,
            // not the exit.
            SubscriptionTerms::ANYTIME => CancellationDecision::accepted(
                'cancel.anytime_under_commitment',
                CancellationDecision::AT_PERIOD_END,
                $periodEnd,
                [
                    'A commitment is in force until ' . $commitmentEnd->format('Y-m-d') . '.',
                    'This offer is cancellable at any time, so it ends with the paid period.',
                ],
            ),

            SubscriptionTerms::AT_COMMITMENT_END => CancellationDecision::accepted(
                'cancel.deferred_to_commitment_end',
                CancellationDecision::AT_COMMITMENT_END,
                $commitmentEnd,
                [
                    'A commitment is in force until ' . $commitmentEnd->format('Y-m-d') . '.',
                    'The request is recorded and takes effect when the commitment ends.',
                ],
            ),

            SubscriptionTerms::AT_TERM => CancellationDecision::accepted(
                'cancel.deferred_to_term',
                CancellationDecision::AT_TERM,
                $termEnd,
                [
                    'A commitment is in force until ' . $commitmentEnd->format('Y-m-d') . '.',
                    'This offer ends at its term, on ' . $termEnd->format('Y-m-d') . '.',
                ],
            ),

            // An unknown policy is refused rather than guessed. Guessing here
            // would either trap a customer who may leave or release one who
            // may not.
            default => CancellationDecision::refused(
                'cancel.unknown_policy',
                [
                    'A commitment is in force until ' . $commitmentEnd->format('Y-m-d') . '.',
                    sprintf('The cancellation policy "%s" is not one this platform knows.', $terms->cancellationPolicy),
                ],
            ),
        };
    }

    /**
     * Whole months remaining, rounded up: a part-month still bills.
     */
    private static function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        $interval = $from->diff($to);
        $months = ($interval->y * 12) + $interval->m;

        return $interval->d > 0 ? $months + 1 : $months;
    }
}
