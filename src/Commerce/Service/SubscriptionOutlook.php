<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\CancellationPolicy;
use App\Commerce\Domain\RenewalPolicy;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionRepository;
use App\Product\Domain\ProductSettings;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * What happens next to a subscription, said by the server (ADR-079).
 *
 * The subscription screen showed a period and a status, and left its reader to
 * work out the rest: when the term ends, whether it renews by itself, until
 * when a cancellation still stops the next charge. Each of those was known
 * only to the mail that announced it, or to nobody. And after the renewal had
 * rolled the row ahead, the screen showed a period that had not begun.
 *
 * Everything here is **derived on read from what the platform already holds**
 * — the terms the subscription snapshotted, the product's renewal setting, the
 * cancellation policy's own decision — and never stored: a second copy of any
 * of them is a second answer that can disagree. The screen renders these and
 * computes none of them (§4, §5): a date worked out in a component is right
 * until the server's rule moves.
 */
final class SubscriptionOutlook
{
    public const ENDS = 'ENDS';
    public const RENEWS_PERIOD = 'RENEWS_PERIOD';
    public const RENEWS_TERM = 'RENEWS_TERM';

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly CancellationPolicy $policy,
        private readonly ProductSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Subscription $subscription, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $renewal = RenewalPolicy::fromConfiguration($this->settings->all($subscription->productId));
        $next = $this->subscriptions->unstartedPeriod($subscription, $now);
        $serviceUntil = $subscription->runningPeriodEndAt($now);
        $decision = $this->policy->decide($subscription, $now);

        $continuation = $this->continuation($subscription, $renewal, $now);
        $termRenews = $continuation === self::RENEWS_TERM ? $subscription->termEndsAt : null;

        return [
            // The end of the period actually running — what is paid for.
            'service_until' => self::moment($serviceUntil),
            // The period invoiced ahead that has not begun, when there is one.
            'next_period' => $next === null ? null : [
                'starts_at' => self::moment($next->startsAt),
                'ends_at' => self::moment($subscription->currentPeriodEnd),
                'invoice_id' => $next->invoiceId,
            ],
            // What happens when the period running ends, if nobody does anything.
            'continuation' => $continuation,
            // When it ends, when that is known: a cancellation's date, or the
            // term of an offer sold as ending there.
            'ends_on' => self::moment($this->endsOn($subscription, $continuation, $serviceUntil)),
            // The day the next term starts, and the last day to refuse it —
            // the two dates the pre-renewal notice carries (ADR-071).
            'term_renews_on' => self::moment($termRenews),
            'term_cancel_by' => $termRenews?->modify(sprintf('-%d days', $renewal->leadDays))->format('Y-m-d'),
            // What a cancellation asked for now would do: the policy's own
            // decision, the one the cancel endpoint would take.
            'if_cancelled_now' => [
                'accepted' => $decision->accepted,
                'rule_id' => $decision->ruleId,
                'effect' => $decision->effect,
                'effective_at' => self::moment($decision->effectiveAt),
            ],
        ];
    }

    private function continuation(Subscription $subscription, RenewalPolicy $renewal, DateTimeImmutable $now): string
    {
        $serviceUntil = $subscription->runningPeriodEndAt($now);

        if ($serviceUntil === null) {
            // No period to end: nothing rolls and nothing lapses.
            return self::ENDS;
        }

        if ($subscription->isDueToEndBy($serviceUntil)) {
            return self::ENDS;
        }

        if ($subscription->hasUnstartedPeriodAt($now)) {
            // Already rolled: the next period exists.
            return self::RENEWS_PERIOD;
        }

        if (!$renewal->automatic) {
            return self::ENDS;
        }

        if ($subscription->isPastDue() && !$subscription->keepsRenewingInArrears()) {
            return self::ENDS;
        }

        $nextEnd = $subscription->offer->version->periodEndFrom($serviceUntil);

        if ($nextEnd === null) {
            return self::ENDS;
        }

        // The same test the renewal makes (`Subscriptions::renew`): reached,
        // or the next period would run past it.
        $lastOfTerm = $subscription->termEndsAt !== null
            && ($subscription->hasReachedTermAt($serviceUntil) || $nextEnd > $subscription->termEndsAt);

        if ($subscription->terms->endsAtTerm()) {
            return $subscription->termEndsAt === null || $lastOfTerm ? self::ENDS : self::RENEWS_PERIOD;
        }

        return $lastOfTerm ? self::RENEWS_TERM : self::RENEWS_PERIOD;
    }

    private function endsOn(Subscription $subscription, string $continuation, ?DateTimeImmutable $serviceUntil): ?DateTimeImmutable
    {
        if ($subscription->cancelEffectiveAt !== null) {
            return $subscription->cancelEffectiveAt;
        }

        if ($continuation === self::ENDS) {
            return $serviceUntil;
        }

        return $subscription->terms->endsAtTerm() ? $subscription->termEndsAt : null;
    }

    private static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }
}
