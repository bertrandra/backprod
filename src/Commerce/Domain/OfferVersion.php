<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * The terms of an offer at one point in its history: price, billing period,
 * commercial window, and what it grants.
 *
 * §12 requires a price or quota change to create a version rather than
 * rewrite history. A subscription therefore refers to a version, not to an
 * offer — otherwise what a tenant bought would change under them the next
 * time the price did.
 *
 * validFrom and validUntil are the window in which this version may be
 * *sold*. §12 is explicit that this is not the tenant's subscription period,
 * and the two are never conflated here.
 */
final class OfferVersion
{
    public const DRAFT = 'DRAFT';
    public const ACTIVE = 'ACTIVE';
    public const EXPIRED = 'EXPIRED';
    public const ARCHIVED = 'ARCHIVED';

    /**
     * @param list<OfferGrant> $grants
     */
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $status,
        public readonly string $billingPeriod,
        public readonly int $priceMinorUnits,
        public readonly string $currency,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly array $grants,
    ) {
    }

    /**
     * Whether this version may be sold at the given moment.
     *
     * Both halves matter. A version can be ACTIVE and outside its window
     * (published early, or past its end without anyone having run a job to
     * mark it EXPIRED), and status alone would sell it. Asking the clock
     * means expiry does not depend on a job having run.
     */
    public function isSellableAt(DateTimeImmutable $moment): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        if ($moment < $this->validFrom) {
            return false;
        }

        return $this->validUntil === null || $moment < $this->validUntil;
    }
}
