<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * A term rolling into the next one, tacitly (ADR-071).
 *
 * The other half of renewal. A period rolling inside a term is the agreed
 * contract running; a term rolling is the same contract, on the same terms,
 * agreed again by not refusing it — which is why it happens only after the
 * customer was told, and only for an offer sold as `AUTO_RENEW`.
 *
 * Both dates are counted from the end of the term that is ending, never from
 * now: renewing a few days early must neither shorten the next term nor push
 * its commitment past what the offer sold. `commitmentEndsAt` is null exactly
 * when the offer sells no commitment, which is what
 * `subscriptions_commitment_is_dated` requires of the row.
 */
final class TermRenewal
{
    public function __construct(
        public readonly DateTimeImmutable $endingTermEndsAt,
        public readonly DateTimeImmutable $termEndsAt,
        public readonly ?DateTimeImmutable $commitmentEndsAt,
    ) {
    }

    /**
     * The next term of these terms, after a term ending at `$termEndsAt`.
     * Null when the terms name no length, so there is no next term to count.
     */
    public static function after(SubscriptionTerms $terms, DateTimeImmutable $termEndsAt): ?self
    {
        $next = $terms->termEndsFrom($termEndsAt);

        if ($next === null) {
            return null;
        }

        return new self($termEndsAt, $next, $terms->commitmentEndsFrom($termEndsAt));
    }
}
