<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;

/**
 * Tenants by id and by slug, in memory, for tests that resolve a context
 * without a database.
 */
final class InMemoryTenantRepository implements TenantRepository
{
    /** @var array<string, Tenant> */
    private array $byId = [];

    /**
     * @param list<Tenant> $tenants
     */
    public function __construct(array $tenants = [])
    {
        foreach ($tenants as $tenant) {
            $this->byId[$tenant->id] = $tenant;
        }
    }

    public function find(string $tenantId): ?Tenant
    {
        return $this->byId[$tenantId] ?? null;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        foreach ($this->byId as $tenant) {
            if ($tenant->slug === $slug) {
                return $tenant;
            }
        }

        return null;
    }

    public function rename(string $tenantId, string $name): void
    {
        $tenant = $this->byId[$tenantId] ?? null;

        if ($tenant !== null) {
            $this->byId[$tenantId] = new Tenant(
                $tenant->id,
                $name,
                $tenant->slug,
                $tenant->mayAuthorOffers,
                $tenant->defaultProductCode,
            );
        }
    }

    /**
     * Accepts whatever it is given, unlike the database, which refuses a
     * product the tenant does not hold: this double knows nothing about
     * assignments, and pretending to would be a second rule to keep in step
     * with the real one.
     */
    public function chooseDefaultProduct(string $tenantId, ?string $productCode): bool
    {
        $tenant = $this->byId[$tenantId] ?? null;

        if ($tenant === null) {
            return false;
        }

        $this->byId[$tenantId] = new Tenant(
            $tenant->id,
            $tenant->name,
            $tenant->slug,
            $tenant->mayAuthorOffers,
            $productCode,
        );

        return true;
    }
}
