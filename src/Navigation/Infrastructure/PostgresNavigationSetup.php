<?php

declare(strict_types=1);

namespace App\Navigation\Infrastructure;

use App\Navigation\Domain\NavigationSetup;
use App\Navigation\Domain\NavigationSetupRepository;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;

/**
 * The menu setup as one row of `platform_settings`, under the key
 * `navigation` — the platform's own configuration, beside the products'
 * per-product one, and the first key in that table.
 *
 * A stored document that no longer parses — a hand edit, a shape from a
 * future version rolled back — reads as *everything*, never as an error:
 * the menu is courtesy, and a broken setting must not take the shell down
 * with it. Saving then overwrites it with a valid one.
 */
final class PostgresNavigationSetup implements NavigationSetupRepository
{
    public const KEY = 'navigation';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): NavigationSetup
    {
        $value = $this->connection->fetchOne(
            'SELECT value FROM platform_settings WHERE key = :key',
            ['key' => self::KEY],
        );

        if (!is_string($value)) {
            return NavigationSetup::initial();
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);

            /** @var array<string, mixed> $decoded */
            return is_array($decoded) ? NavigationSetup::fromArray($decoded) : NavigationSetup::initial();
        } catch (\JsonException | InvalidArgumentException) {
            return NavigationSetup::initial();
        }
    }

    public function save(NavigationSetup $setup): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_settings (key, value)
                VALUES (:key, CAST(:value AS jsonb))
                ON CONFLICT (key)
                DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['key' => self::KEY, 'value' => json_encode($setup->toArray(), JSON_THROW_ON_ERROR)],
        );
    }
}
