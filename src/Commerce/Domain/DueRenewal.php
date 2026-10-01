<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * One subscription whose paid period is about to end (ADR-068).
 *
 * Deliberately thin. It names the subscription well enough for
 * {@see \App\Commerce\Service\Subscriptions::renew()} to be asked the real
 * question, and carries no offer, no terms and no price — because that service
 * re-reads the subscription and already holds every refusal that matters: a
 * cancellation already due, a change the customer asked for, an offer sold as
 * ending at its term. A fat value here would be a second place those rules could
 * be decided from, and the job would be the one deciding them.
 *
 * `currentPeriodEnd` is carried for one reason: it is what the renewal is
 * conditioned on, so the update that moves it can refuse when somebody else has
 * moved it first. Two overlapping passes must not bill one period twice.
 *
 * `daysUntilEnd` is counted **by the database**, the same `floor(epoch / 86400)`
 * the overdue read uses, so the two cannot disagree about what a day is. The
 * lead a product chose is per product while the query is not, so the query
 * bounds broadly and the handler decides per row — the shape the dunning pass
 * already has for the same reason.
 */
final class DueRenewal
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $subscriberUserId,
        public readonly DateTimeImmutable $currentPeriodEnd,
        public readonly int $daysUntilEnd,
    ) {
    }
}
