<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * One capability an offer version grants, and how much of it.
 *
 * A null limit means two different things depending on the feature, and the
 * distinction is load-bearing: for a BOOLEAN feature there is nothing to
 * count, and for a QUOTA feature it means unlimited. Callers ask
 * isUnlimited() rather than testing the null themselves, so neither reading
 * is arrived at by accident.
 */
final class OfferGrant
{
    public function __construct(
        public readonly Feature $feature,
        public readonly ?int $limit,
    ) {
    }

    public function isUnlimited(): bool
    {
        return $this->feature->isQuota() && $this->limit === null;
    }
}
