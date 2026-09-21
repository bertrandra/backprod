<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * Whether a tenant holds a product (ADR-047's `tenant_products`), asked by
 * a product about itself: a tenant that does not is absent to that product,
 * by key as by session, so existence does not leak through a service route.
 */
interface TenantHoldings
{
    public function holds(string $tenantId, string $productId): bool;
}
