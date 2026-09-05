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
     *
     * `rate_limit_counters` has to be named explicitly, unlike most of what
     * this clears. Everything else is reachable from `products`, `tenants` or
     * `users` by CASCADE, so a new table with a foreign key gets truncated
     * without anybody remembering. The counters reference nothing — they are
     * keyed by an address — so CASCADE never reaches them, and left behind
     * they would accumulate across the whole suite until an unrelated test
     * tripped the limit.
     */
    private const TABLES = 'rate_limit_counters, erasure_requests, assets, job_runs, jobs, '
        . 'messages, conversation_participants, conversations, '
        . 'staff_access_log, platform_staff, '
        . 'einvoice_events, einvoice_transmissions, order_lines, orders, '
        . 'quote_lines, quotes, credit_note_lines, credit_notes, payment_events, refunds, '
        . 'payments, financial_events, tax_records, invoice_lines, invoices, '
        . 'billing_profiles, entitlements, subscription_events, subscriptions, '
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
