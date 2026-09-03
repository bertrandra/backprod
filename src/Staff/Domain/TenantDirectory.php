<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use App\Tenant\Domain\Tenant;

/**
 * Reading tenants without belonging to one.
 *
 * A port of its own rather than extra methods on `TenantRepository`, and the
 * reason is the reason for most of §12.2: an unscoped `listAll()` sitting on
 * the repository every tenant-scoped service already injects is an accident
 * waiting to be typed. Here, the only code that can enumerate tenants is code
 * that asked for this interface — and everything that asks for it is behind a
 * staff route and writes to the trail.
 */
interface TenantDirectory
{
    /**
     * @return list<Tenant>
     */
    public function list(int $limit, int $offset): array;

    public function count(): int;

    public function find(string $tenantId): ?Tenant;
}
