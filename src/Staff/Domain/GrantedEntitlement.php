<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use DateTimeImmutable;

/**
 * What the platform gave a tenant on one product, without a sale
 * (docs/tenant-roots.md §2.8): the features and their limits, until when,
 * and who decided it. Read back as one thing per (tenant, product), because
 * that is how it was granted and how it is withdrawn.
 */
final class GrantedEntitlement
{
    /**
     * @param list<GrantedFeature> $features
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly array $features,
        /**
         * Whether this grant lets the tenant's members reach the product, or
         * only adds a feature (2026-09-25). The difference between a trial
         * and a support exception, and the platform says which — false
         * unless somebody chose otherwise, because the wider answer is the
         * one that should take a decision.
         */
        public readonly bool $coversPeople,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly ?string $grantedBy,
        public readonly DateTimeImmutable $grantedAt,
    ) {
    }
}
