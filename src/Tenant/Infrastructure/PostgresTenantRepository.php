<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantRepository implements TenantRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $tenantId): ?Tenant
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, name, slug FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $slug = $row['slug'] ?? null;

        if (!is_string($id) || !is_string($name) || !is_string($slug)) {
            return null;
        }

        return new Tenant($id, $name, $slug);
    }

    public function rename(string $tenantId, string $name): void
    {
        // The slug is deliberately untouched: it may already appear in links
        // and stored references, so renaming a tenant is a display change.
        $this->connection->executeStatement(
            'UPDATE tenants SET name = :name, updated_at = now() WHERE id = :id',
            ['name' => $name, 'id' => $tenantId],
        );
    }
}
