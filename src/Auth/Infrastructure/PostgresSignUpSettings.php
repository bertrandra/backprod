<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\SignUpSettings;
use Doctrine\DBAL\Connection;

/**
 * The sign-up policy as one platform setting (`platform_settings`, key
 * `sign_up`), like the menus, the default tenant and the storefront.
 *
 * No row means no row: `false`, which is off (ADR-063). A value that is not
 * a JSON boolean is read as off too — a setting that decided who may use the
 * platform on the strength of a truthy string would be one nobody could
 * predict.
 */
final class PostgresSignUpSettings implements SignUpSettings
{
    public const KEY = 'sign_up';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function emailConfirmationRequired(): bool
    {
        return $this->connection->fetchOne(
            "SELECT value->>'confirm_email' FROM platform_settings WHERE key = :key",
            ['key' => self::KEY],
        ) === 'true';
    }

    public function requireEmailConfirmation(bool $required): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode(['confirm_email' => $required], JSON_THROW_ON_ERROR)],
        );
    }
}
