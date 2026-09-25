<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use DateTimeImmutable;

/**
 * Entitlements the platform grants (docs/tenant-roots.md §2.8).
 *
 * Assigning a product (TenantProducts, ADR-047) says an organisation may
 * *see* it; a grant says what it may *do* with it, without a subscription.
 * The resolver reads the rows like any other source, so nothing in the
 * §10.6 chain knows this port exists.
 */
interface TenantGrants
{
    /**
     * The grant in force or expired on this product, or null when nothing
     * was granted.
     */
    public function of(string $tenantId, string $productId): ?GrantedEntitlement;

    /**
     * Replaces the grant: whatever GRANT rows existed for the pair go, these
     * come, in one transaction. Feature codes are the product's own; an
     * unknown one is refused before anything is written.
     *
     * @param array<string, int|null> $limits feature code → limit (null = unlimited)
     *
     * @throws \App\Shared\Exceptions\NotFoundException FEATURE_NOT_FOUND
     */
    public function grant(
        string $tenantId,
        string $productId,
        array $limits,
        bool $coversPeople,
        ?DateTimeImmutable $validUntil,
        string $staffUserId,
    ): GrantedEntitlement;

    /** Withdraws the grant. True when there was one. */
    public function withdraw(string $tenantId, string $productId): bool;

    /**
     * The grants of the most recently activated offer version on a plan, as
     * feature code → limit — the shorthand a grant may start from.
     *
     * @return array<string, int|null>|null null when no plan has the code
     */
    public function limitsOfPlan(string $productId, string $planCode): ?array;
}
