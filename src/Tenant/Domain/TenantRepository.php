<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

interface TenantRepository
{
    public function find(string $tenantId): ?Tenant;

    public function rename(string $tenantId, string $name): void;
}
