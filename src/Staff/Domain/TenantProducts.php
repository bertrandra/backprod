<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use App\Product\Domain\Product;
use App\Shared\Exceptions\ConflictException;

/**
 * Which products a tenant holds, and the platform deciding it (ADR-047).
 *
 * A port of its own beside `TenantDirectory`, for the reason that one exists
 * beside `TenantRepository`: only code that asked for this interface can
 * change what a customer may reach, and everything that asks for it sits
 * behind a staff route that records what it did.
 */
interface TenantProducts
{
    /**
     * The products one tenant holds, in code order.
     *
     * @return list<Product>
     */
    public function of(string $tenantId): array;

    /**
     * The same, for a page of tenants at once — one query, not one per row.
     *
     * @param list<string> $tenantIds
     *
     * @return array<string, list<Product>> keyed by tenant id; a tenant with
     *                                      nothing assigned is absent
     */
    public function ofMany(array $tenantIds): array;

    /**
     * Give a tenant a product, and make every current member a member of it.
     *
     * Idempotent: a product already held is the state that was asked for.
     * Null when there is no such tenant or product — a missing row is a 404,
     * and nothing else is.
     *
     * @throws ConflictException PRODUCT_INACTIVE when the product is retired:
     *                           a retired product has every door closed, and
     *                           assigning one hands a customer a locked door
     */
    public function assign(string $tenantId, string $productId, string $staffUserId): ?Product;

    /**
     * Take a product back from a tenant. The memberships in it go with it,
     * by the schema's cascade.
     *
     * Null when there is no such tenant or product; a product the tenant
     * never held is the state that was asked for, and answers the product.
     *
     * @throws ConflictException PRODUCT_IN_USE while a subscription on this
     *                           tenant and product is still owed service —
     *                           active, or cancelled with paid time left.
     *                           Withdrawing the product would cut off what
     *                           the customer has paid for
     */
    public function unassign(string $tenantId, string $productId): ?Product;
}
