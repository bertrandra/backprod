<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use App\Product\Domain\Product;
use App\Tenant\Domain\Tenant;

/**
 * A tenant as the platform sees it: the tenant, and the products it holds.
 *
 * Two things rather than a `products` field on `Tenant`, because `Tenant` is
 * also what a tenant's own administrator reads about their organisation, and
 * which products the platform has assigned them is the platform's answer to
 * give — through the console, behind `staff.tenants.read` — not a field that
 * happens to be on the same object.
 */
final class TenantAccount
{
    /**
     * @param list<Product> $products
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly array $products,
    ) {
    }
}
