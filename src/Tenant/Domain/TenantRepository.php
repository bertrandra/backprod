<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

interface TenantRepository
{
    public function find(string $tenantId): ?Tenant;

    /**
     * By the word in its URL root (2026-09-17): `hostname/{slug}/` is the
     * organisation's address, and the address is what a request names.
     */
    public function findBySlug(string $slug): ?Tenant;

    public function rename(string $tenantId, string $name): void;
}
