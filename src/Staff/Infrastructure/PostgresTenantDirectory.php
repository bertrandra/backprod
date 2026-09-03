<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Staff\Domain\TenantDirectory;
use App\Tenant\Domain\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class PostgresTenantDirectory implements TenantDirectory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function list(int $limit, int $offset): array
    {
        // Ordered by name then id: an operator scanning the list wants it
        // alphabetical, and the id breaks ties so paging is stable when two
        // tenants share a name.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, name, slug
                  FROM tenants
                 ORDER BY name, id
                 LIMIT :limit OFFSET :offset
                SQL,
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toTenant(...), $rows);
    }

    public function count(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM tenants');

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId): ?Tenant
    {
        if (!Uuid::isValid($tenantId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, name, slug FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        );

        return $row === false ? null : self::toTenant($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toTenant(array $row): Tenant
    {
        return new Tenant(
            Row::string($row, 'id'),
            Row::string($row, 'name'),
            Row::string($row, 'slug'),
        );
    }
}
