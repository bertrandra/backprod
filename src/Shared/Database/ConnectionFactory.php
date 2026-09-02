<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Builds the DBAL connection from configuration.
 *
 * DBAL connects lazily, so an unconfigured deployment still serves the
 * liveness probe and fails only on the first request that needs data. That is
 * the right trade: a database outage should not make the process look dead to
 * an orchestrator.
 */
final class ConnectionFactory
{
    /**
     * DBAL 4 removed the `url` parameter, so a DSN has to be parsed into
     * driver parameters explicitly. The scheme map is what turns
     * `postgresql://…` into the pdo_pgsql driver.
     */
    private const SCHEMES = [
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function paramsFromDsn(#[SensitiveParameter] string $dsn): array
    {
        if ($dsn === '') {
            throw new RuntimeException('DATABASE_DSN is not configured.');
        }

        try {
            return (new DsnParser(self::SCHEMES))->parse($dsn);
        } catch (Throwable $e) {
            // The DSN carries credentials, so the original message — which
            // may quote it — is not propagated (§31).
            throw new RuntimeException('DATABASE_DSN is not a usable connection string.', 0, $e);
        }
    }

    public static function fromDsn(#[SensitiveParameter] string $dsn): Connection
    {
        $params = self::paramsFromDsn($dsn);

        try {
            return DriverManager::getConnection($params);
        } catch (Throwable $e) {
            throw new RuntimeException('The configured DATABASE_DSN could not be used.', 0, $e);
        }
    }
}
