<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use RuntimeException;
use SensitiveParameter;

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
    public static function fromDsn(#[SensitiveParameter] string $dsn): Connection
    {
        if ($dsn === '') {
            throw new RuntimeException('DATABASE_DSN is not configured.');
        }

        try {
            return DriverManager::getConnection(['url' => $dsn]);
        } catch (DbalException $e) {
            // The DSN carries credentials, so the original message is not
            // propagated (§31).
            throw new RuntimeException('The configured DATABASE_DSN could not be used.', 0, $e);
        }
    }
}
