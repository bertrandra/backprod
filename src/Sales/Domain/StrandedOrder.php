<?php

declare(strict_types=1);

namespace App\Sales\Domain;

/**
 * An order still waiting for its payment, and for how long (ADR-079).
 *
 * What `sweep.orders` reads. The age is PostgreSQL's — whole hours since the
 * order last moved — so the pass and the database cannot come to disagree
 * about what an hour is, the reason the renewal and the collection pass are
 * handed their days rather than computing them.
 */
final class StrandedOrder
{
    public function __construct(
        public readonly Order $order,
        public readonly int $hoursWaiting,
    ) {
    }
}
