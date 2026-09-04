<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * A rate, for a country, over a window (§25.3).
 *
 * There is no "current rate" here, and that absence is the design. A rate
 * announced for 1 January is inserted in advance with its window and applies
 * on the day by itself — no deployment, no script. The corollary matters
 * more: correcting a rate closes one window and opens another, so a rate that
 * moves by law shifts no euro of VAT already invoiced, and a declaration
 * replayed two years later still gives the same figure.
 *
 * This is the fifth place the platform applies the same rule — offer windows,
 * entitlement validity, subscription periods, quote expiry, job leases:
 *
 *     a lapse is a fact about the clock, never about whether something ran.
 */
final class TaxRate
{
    public const STANDARD = 'STANDARD';
    public const REDUCED = 'REDUCED';
    public const ZERO = 'ZERO';

    public function __construct(
        public readonly string $id,
        public readonly string $countryCode,
        public readonly string $rateKind,
        public readonly int $basisPoints,
        public readonly DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly ?string $source,
    ) {
    }

    /**
     * Whether this rate governs the given moment.
     *
     * Half-open on purpose: `valid_until` is the instant the next rate takes
     * over, so a sale at exactly that instant is taxed by the successor. A
     * closed interval would make both rates answer at the boundary, and the
     * database's exclusion constraint would have refused the pair anyway.
     */
    public function appliesAt(DateTimeImmutable $moment): bool
    {
        if ($moment < $this->validFrom) {
            return false;
        }

        return $this->validUntil === null || $moment < $this->validUntil;
    }
}
