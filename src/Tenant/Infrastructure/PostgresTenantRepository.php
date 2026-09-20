<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Shared\Database\Uuid;
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
        // A tenant id that is not a uuid names nothing; asked of PostgreSQL
        // it would be a type error, not an absence.
        if (!Uuid::isValid($tenantId)) {
            return null;
        }

        return $this->one('SELECT id, name, slug, may_author_offers FROM tenants WHERE id = :key', $tenantId);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->one('SELECT id, name, slug, may_author_offers FROM tenants WHERE slug = :key', $slug);
    }

    private function one(string $sql, string $key): ?Tenant
    {
        $row = $this->connection->fetchAssociative($sql, ['key' => $key]);

        if ($row === false) {
            return null;
        }

        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $slug = $row['slug'] ?? null;

        if (!is_string($id) || !is_string($name) || !is_string($slug)) {
            return null;
        }

        // Read rather than left to the constructor's default: a tenant that
        // reports `false` while the platform has delegated the catalogue to it
        // is worse than one that reports nothing.
        return new Tenant($id, $name, $slug, $row['may_author_offers'] === true);
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
