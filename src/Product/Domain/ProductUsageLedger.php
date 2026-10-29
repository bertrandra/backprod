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
     * Runs `$work` in one transaction, holding the one lock every report
     * for this tenant, product and feature takes — so the quota read and the
     * insert it allows cannot be split by a concurrent report that read the
     * same level.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function exclusively(string $tenantId, string $productId, string $feature, callable $work): mixed;

    /**
     * Whether this idempotency key was already recorded: a retry, answered
     * as one, before any quota is asked about.
     */
    public function isRecorded(string $tenantId, string $productId, string $feature, string $idempotencyKey): bool;

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
