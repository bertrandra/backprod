<?php

declare(strict_types=1);

namespace App\Finance\Domain;

/**
 * What one offer earned in one month, in one currency.
 *
 * Attributed through the invoice *line*, which snapshots the offer version it
 * was priced from (§25). Reading it back through the subscription's current
 * offer would credit today's offer with money an older one earned, and would
 * credit it differently again after an upgrade — so last month's answer would
 * change without last month changing.
 */
final class OfferRevenue
{
    public function __construct(
        public readonly string $offerId,
        public readonly string $offerCode,
        public readonly string $offerName,
        public readonly string $currency,
        public readonly int $netMinorUnits,
        public readonly int $linesBilled,
    ) {
    }
}
