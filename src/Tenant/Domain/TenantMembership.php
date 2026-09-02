<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * A user's membership of one tenant, for one product.
 *
 * Membership is per product because §12.1 lets a tenant use one or several
 * products; a user may administer a tenant in one product and have no access
 * to it in another.
 */
final class TenantMembership
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $productId,
        public readonly array $roles,
    ) {
    }
}
