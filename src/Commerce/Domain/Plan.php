<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * A commercial tier — §12's Offer.type, §13's Plan.
 *
 * It carries a rank rather than an ordering the code knows, so "is this an
 * upgrade?" is answered by comparing two numbers from the database instead of
 * by a list of names in a constant. §13 bans the second form for a reason:
 * the names change, and every place that knew them is then wrong.
 */
final class Plan
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly int $rank,
    ) {
    }
}
