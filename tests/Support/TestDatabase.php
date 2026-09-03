<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Database\ConnectionFactory;
use Doctrine\DBAL\Connection;

/**
 * The test database, connected and emptied the same way everywhere.
 *
 * Two base classes need this — one drives repositories directly, the other
 * drives the HTTP pipeline against the same schema — and a truncate list
 * that exists twice is a truncate list that will disagree with itself the
 * next time a table is added.
 */
final class TestDatabase
{
    /**
     * Reference data is deliberately absent: roles and permissions are
     * created by the migrations, not by fixtures, and clearing them would
     * leave the platform unable to authorise anything.
     */
    private const TABLES = 'entitlements, subscription_events, subscriptions, '
        . 'offer_version_features, offer_versions, offers, plans, features, '
        . 'project_versions, projects, tenant_member_roles, tenant_members, '
        . 'tenants, products, users';

    public static function dsn(): ?string
    {
        $dsn = $_ENV['DATABASE_DSN'] ?? getenv('DATABASE_DSN');

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    public static function connect(string $dsn): Connection
    {
        return ConnectionFactory::fromDsn($dsn);
    }

    /**
     * Truncating rather than recreating keeps the migrated schema, including
     * the constraints that are half the point of these tests.
     */
    public static function reset(Connection $connection): void
    {
        $connection->executeStatement('TRUNCATE ' . self::TABLES . ' RESTART IDENTITY CASCADE');
    }
}
