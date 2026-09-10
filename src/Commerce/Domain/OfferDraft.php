<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * The terms somebody is proposing to sell, before they are sold.
 *
 * A draft is deliberately not an {@see OfferVersion}: a version has an id, a
 * number and a status, all of which the database assigns, and a draft has
 * none of them. Handing the repository a half-built OfferVersion with an
 * empty id would mean every reader of a version had to wonder which kind it
 * was holding.
 *
 * Grants travel as feature id to limit rather than as {@see OfferGrant}
 * objects, because the caller names features by id and the {@see Feature}
 * behind each one is exactly what the repository is about to verify belongs
 * to this product. Resolving them here would mean resolving them twice.
 */
final class OfferDraft
{
    /**
     * @param array<string, int|null> $grants feature id to limit; null is unlimited for a
     *                                        quota and the only valid value for a boolean
     */
    public function __construct(
        public readonly string $billingPeriod,
        public readonly int $priceMinorUnits,
        public readonly string $currency,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly array $grants,
        public readonly SubscriptionTerms $terms,
    ) {
    }
}
