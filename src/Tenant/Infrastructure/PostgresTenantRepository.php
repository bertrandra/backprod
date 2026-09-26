<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Shared\Database\Uuid;
use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantRepository implements TenantRepository
{
    /**
     * The default product is joined out to its **code** here rather than
     * carried as an id (2026-09-26): a code is what `?product=`, the switcher
     * and `X-Product` all speak, and translating it at every call site would
     * be the same lookup written four times.
     */
    private const SELECT = <<<'SQL'
        SELECT t.id, t.name, t.slug, t.may_author_offers, p.code AS default_product
          FROM tenants t
          LEFT JOIN products p ON p.id = t.default_product_id
        SQL;

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

        return $this->one(self::SELECT . ' WHERE t.id = :key', $tenantId);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->one(self::SELECT . ' WHERE t.slug = :key', $slug);
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

        $default = $row['default_product'] ?? null;

        // Read rather than left to the constructor's default: a tenant that
        // reports `false` while the platform has delegated the catalogue to it
        // is worse than one that reports nothing.
        return new Tenant(
            $id,
            $name,
            $slug,
            $row['may_author_offers'] === true,
            is_string($default) ? $default : null,
        );
    }

    public function chooseDefaultProduct(string $tenantId, ?string $productCode): bool
    {
        if ($productCode === null) {
            $this->connection->executeStatement(
                'UPDATE tenants SET default_product_id = NULL, updated_at = now() WHERE id = :id',
                ['id' => $tenantId],
            );

            return true;
        }

        // The subquery is scoped to what the tenant holds, so a product the
        // platform never assigned it matches nothing and the statement
        // touches no row — which is the `false` below. The composite foreign
        // key says the same thing a moment later, and this is what turns it
        // into an answer instead of a driver exception.
        $changed = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenants
                   SET default_product_id = (
                           SELECT tp.product_id
                             FROM tenant_products tp
                             JOIN products p ON p.id = tp.product_id
                            WHERE tp.tenant_id = tenants.id
                              AND p.code = :code
                       ),
                       updated_at = now()
                 WHERE id = :id
                   AND EXISTS (
                         SELECT 1
                           FROM tenant_products tp
                           JOIN products p ON p.id = tp.product_id
                          WHERE tp.tenant_id = tenants.id
                            AND p.code = :code
                       )
                SQL,
            ['id' => $tenantId, 'code' => $productCode],
        );

        return $changed > 0;
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
