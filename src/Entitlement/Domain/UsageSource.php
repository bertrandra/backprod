<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

/**
 * How much of a metered thing a tenant is currently using.
 *
 * A port, so the entitlement module never learns what a project or a member
 * is. Each metered feature has its own implementation living in the module
 * that owns the thing being counted, and the container maps feature codes to
 * them — which is why adding a new quota is a registration rather than a
 * change here.
 */
interface UsageSource
{
    public function usage(string $tenantId, string $productId): int;
}
