<?php

declare(strict_types=1);

/**
 * Doctrine Migrations configuration (ADR-016).
 */
return [
    'table_storage' => [
        'table_name' => 'schema_migrations',
        'version_column_name' => 'version',
        'version_column_length' => 191,
        'executed_at_column_name' => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],

    'migrations_paths' => [
        'App\Migrations' => __DIR__ . '/migrations',
    ],

    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => true,
];
