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

    public static function fromDsn(#[SensitiveParameter] string $dsn): Connection
    {
        self::assertConfigured($dsn);

        try {
            // Parsed inline rather than through paramsFromDsn(): DriverManager
            // requires a precise parameter shape, and returning that shape
            // from a method would widen it to array<string, mixed>.
            return DriverManager::getConnection((new DsnParser(self::SCHEMES))->parse($dsn));
        } catch (Throwable $e) {
            throw self::unusable($e);
        }
    }

    /**
     * The same parameters, for the Doctrine Migrations CLI, which runs
     * without the application container and takes an array rather than a
     * connection.
     *
     * @return array<string, mixed>
     */
    public static function paramsFromDsn(#[SensitiveParameter] string $dsn): array
    {
        self::assertConfigured($dsn);

        try {
            return (new DsnParser(self::SCHEMES))->parse($dsn);
        } catch (Throwable $e) {
            throw self::unusable($e);
        }
    }

    private static function assertConfigured(#[SensitiveParameter] string $dsn): void
    {
        if ($dsn === '') {
            throw new RuntimeException('DATABASE_DSN is not configured.');
        }
    }

    /**
     * The DSN carries credentials, so the original message — which may quote
     * it — is kept as a previous exception rather than surfaced (§31).
     */
    private static function unusable(Throwable $cause): RuntimeException
    {
        return new RuntimeException('DATABASE_DSN is not a usable connection string.', 0, $cause);
    }
}
