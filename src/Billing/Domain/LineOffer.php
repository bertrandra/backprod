<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * What a document line sold, said in words a customer knows (2026-09-19):
 * the product, the offer and its plan, how it is billed, which version.
 *
 * Read at presentation time from the offer version the line names, never
 * stored: the version is immutable (ADR-033), so what it belonged to is a
 * fact that does not move, and the amounts — the snapshot — stay on the
 * line itself. A line from before versions were recorded has none.
 */
final class LineOffer
{
    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        public readonly string $offerCode,
        public readonly string $offerName,
        public readonly string $planName,
        public readonly string $billingPeriod,
        public readonly int $version,
    ) {
    }
}
