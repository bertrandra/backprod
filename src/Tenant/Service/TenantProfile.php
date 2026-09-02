<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;

/**
 * Reading and renaming the tenant a request resolved to.
 */
final class TenantProfile
{
    public function __construct(private readonly TenantRepository $tenants)
    {
    }

    public function current(string $tenantId): Tenant
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            // The context chain resolved a membership pointing at this
            // tenant, so its absence means the row vanished mid-request —
            // not that the caller asked for something they may not see.
            throw new NotFoundException('The current tenant no longer exists.', [], 'TENANT_NOT_FOUND');
        }

        return $tenant;
    }

    public function rename(string $tenantId, string $name): Tenant
    {
        // Read first, so renaming a tenant that has gone fails as not found
        // rather than silently updating nothing and reporting success.
        $this->current($tenantId);
        $this->tenants->rename($tenantId, $name);

        return $this->current($tenantId);
    }
}
