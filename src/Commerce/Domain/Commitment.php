<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * How long a subscription cannot be cancelled, and until when (§13.1).
 *
 * Two values that the database refuses to hold apart —
 * `(commitment_months > 0) = (commitment_ends_at IS NOT NULL)` — so they
 * travel together here rather than as two arguments a caller could pair
 * wrongly. Zero months and a null date is "no commitment"; the absence of a
 * commitment is a fact, not a missing one.
 */
final class Commitment
{
    public function __construct(
        public readonly int $months,
        public readonly ?DateTimeImmutable $endsAt,
    ) {
    }

    public static function none(): self
    {
        return new self(0, null);
    }

    /**
     * The commitment an offer version sells, counted from a moment.
     */
    public static function sold(SubscriptionTerms $terms, DateTimeImmutable $at): self
    {
        return new self($terms->commitmentMonths, $terms->commitmentEndsFrom($at));
    }

    /**
     * Whether this commitment binds past the end of another one.
     *
     * No commitment binds past nothing, so a null date never wins: the
     * comparison is about the date, and a commitment with no date has none.
     */
    public function endsLaterThan(self $other): bool
    {
        return $this->endsAt !== null && ($other->endsAt === null || $this->endsAt > $other->endsAt);
    }
}
