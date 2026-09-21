<?php

declare(strict_types=1);

namespace App\Product\Domain;

use DateTimeImmutable;

/**
 * What a product metered against a quota (ADR-051 §4, §38.3): signed
 * deltas whose sum is the level. Once per idempotency key, because a job
 * that timed out after the platform wrote will send the same fact again.
 */
interface ProductUsageLedger
{
    /**
     * Records the delta. False when this key was already recorded, in which
     * case nothing was written — the earlier row stands.
     */
    public function record(
        string $tenantId,
        string $productId,
        ?string $credentialId,
        string $feature,
        int $quantity,
        string $idempotencyKey,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
    ): bool;
}
