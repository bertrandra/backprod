<?php

declare(strict_types=1);

use App\Shared\Database\ConnectionFactory;

/**
 * Connection parameters for the Doctrine Migrations CLI.
 *
 * The CLI runs without the application container, but it goes through the
 * same factory so there is one place that knows how to turn DATABASE_DSN into
 * driver parameters — DBAL 4 no longer accepts a DSN directly.
 */
$dsn = $_ENV['DATABASE_DSN'] ?? getenv('DATABASE_DSN');

if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "DATABASE_DSN is not set; migrations have no database to run against.\n");

    exit(1);
}

return ConnectionFactory::paramsFromDsn($dsn);
