<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\StorefrontSettings;
use Doctrine\DBAL\Connection;

/**
 * The storefront's settings as one platform setting (`platform_settings`,
 * key `storefront`), like the default tenant and the menus. Absent means the
 * default: pay there and then.
 */
final class PostgresStorefrontSettings implements StorefrontSettings
{
    public const KEY = 'storefront';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function afterSignUp(): string
    {
        $value = $this->connection->fetchOne(
            "SELECT value->>'after_sign_up' FROM platform_settings WHERE key = :key",
            ['key' => self::KEY],
        );

        return is_string($value) && in_array($value, self::AFTER_SIGN_UP, true) ? $value : self::PAY;
    }

    public function setAfterSignUp(string $choice): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode(['after_sign_up' => $choice], JSON_THROW_ON_ERROR)],
        );
    }
}
