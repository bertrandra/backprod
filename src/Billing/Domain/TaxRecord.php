<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * The VAT applied at one rate on one invoice.
 *
 * Stored per rate rather than recomputed from lines, because a VAT return is
 * filed per rate, and recomputing later would mean recomputing from rounding
 * decisions nobody wrote down.
 */
final class TaxRecord
{
    public function __construct(
        public readonly string $jurisdiction,
        public readonly int $rateBasisPoints,
        public readonly Money $taxable,
        public readonly Money $tax,
    ) {
    }
}
