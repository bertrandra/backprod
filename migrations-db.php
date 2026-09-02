<?php

declare(strict_types=1);

/**
 * Connection parameters for the Doctrine Migrations CLI.
 *
 * Separate from config/container.php because the CLI runs without the
 * application container — but it reads the same DATABASE_DSN, so there is one
 * source of truth for where the database is.
 */
$dsn = $_ENV['DATABASE_DSN'] ?? getenv('DATABASE_DSN');

if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "DATABASE_DSN is not set; migrations have no database to run against.\n");

    exit(1);
}

return ['url' => $dsn];
