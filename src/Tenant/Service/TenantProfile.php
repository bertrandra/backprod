<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Exceptions\BadRequestException;
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

    /**
     * Names the product this organisation opens on, or clears it
     * (2026-09-26).
     *
     * A courtesy, never an authority: it decides where a screen opens and
     * nothing about what anybody may reach there. Somebody who has chosen a
     * product on their own profile keeps it, and an address naming one wins
     * over both.
     *
     * @throws BadRequestException when the code names no product this tenant
     *                             holds — which is the database's answer,
     *                             read back rather than guessed at
     */
    public function chooseDefaultProduct(string $tenantId, ?string $productCode): Tenant
    {
        $this->current($tenantId);

        if (!$this->tenants->chooseDefaultProduct($tenantId, $productCode)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'default_product', 'requirement' => 'must be a product this organisation holds'],
            );
        }

        return $this->current($tenantId);
    }
}
