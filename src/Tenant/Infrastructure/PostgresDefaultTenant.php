<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\DefaultTenant;
use Doctrine\DBAL\Connection;

/**
 * The default tenant as `platform_settings.default_tenant = {tenant_id}`,
 * the second key in that table after the menu setup.
 */
final class PostgresDefaultTenant implements DefaultTenant
{
    public const KEY = 'default_tenant';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function id(): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT value->>\'tenant_id\' FROM platform_settings WHERE key = :key',
            ['key' => self::KEY],
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(?string $tenantId): void
    {
        if ($tenantId === null) {
            $this->connection->executeStatement('DELETE FROM platform_settings WHERE key = :key', ['key' => self::KEY]);

            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode(['tenant_id' => $tenantId], JSON_THROW_ON_ERROR)],
        );
    }
}
