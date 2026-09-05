<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * One person who must be told, before one subscription renews itself (§13.1).
 *
 * A row per recipient rather than per subscription, because the obligation is
 * to tell *somebody who can act on it* — and for a tenant subscription that
 * is every administrator, not the abstraction that holds the contract.
 *
 * `recipientUserId` is nullable on purpose. A tenant subscription whose tenant
 * has no administrator is due a notice that reaches nobody, and that is a fact
 * worth surfacing: silently returning no row would make an unmet legal
 * obligation look like an empty queue. R11 is the risk this exists for, and
 * the shape of the failure it warns about is exactly "we thought we told
 * them".
 */
final class RenewalNotice
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly DateTimeImmutable $termEndsAt,
        public readonly int $noticeDays,
        public readonly ?string $recipientUserId,
    ) {
    }

    /**
     * Due for this term and nobody else's.
     *
     * The term end is part of the key, so a subscription renewing year after
     * year is noticed once a year rather than once ever. The subscription id
     * alone would silence every renewal after the first.
     */
    public function dedupKey(): string
    {
        return $this->subscriptionId . ':' . $this->termEndsAt->format('Y-m-d');
    }
}
