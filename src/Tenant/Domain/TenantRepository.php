<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

interface TenantRepository
{
    public function find(string $tenantId): ?Tenant;

    /**
     * By the word in its URL root (2026-09-17): `hostname/{slug}/` is the
     * organisation's address, and the address is what a request names.
     */
    public function findBySlug(string $slug): ?Tenant;

    public function rename(string $tenantId, string $name): void;

    /**
     * Names the product this organisation opens on, or clears it
     * (2026-09-26).
     *
     * By code, like everything else that speaks about a product on this
     * surface. A code the organisation does not hold is refused by the
     * database, so this reports what happened rather than checking first:
     * two administrators unassigning and defaulting at the same moment
     * would race straight through a check.
     *
     * @return bool false when the code names no product this tenant holds
     */
    public function chooseDefaultProduct(string $tenantId, ?string $productCode): bool;
}
