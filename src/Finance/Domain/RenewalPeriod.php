<?php

declare(strict_types=1);

namespace App\Finance\Domain;

/**
 * How many subscriptions reached a period boundary in a month, and what
 * became of them.
 *
 * Counts, never a stored rate. A ratio kept beside its inputs is a second
 * copy of the same fact, and the two drift the first time one is recomputed
 * and the other is not.
 *
 * The rate is null — not zero — when nothing came up for renewal. Zero would
 * claim everybody left. That distinction is load-bearing right now: nothing
 * renews yet, because a finished period goes to EXPIRED and no job calls
 * renew() while the tacit-renewal notice deadlines are unconfirmed (R11). A
 * dashboard reading 0% would be reporting an unbuilt feature as catastrophic
 * churn.
 */
final class RenewalPeriod
{
    public function __construct(
        public readonly string $periodStart,
        public readonly int $dueCount,
        public readonly int $renewedCount,
        public readonly int $endedCount,
    ) {
    }

    /**
     * Renewals as a percentage, to one decimal — or null when nothing was due.
     */
    public function ratePercent(): ?float
    {
        if ($this->dueCount === 0) {
            return null;
        }

        return round(100 * $this->renewedCount / $this->dueCount, 1);
    }
}
