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
    private const TABLES = 'platform_settings, rate_limit_counters, erasure_requests, assets, job_runs, jobs, '
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

    /**
     * One connection per DSN for the whole process (2026-09-20). Opening one
     * is a `postgres.exe` spawned on Windows — a third of a second — and
     * every test opened two: its own, and the application's through the
     * container. The database is emptied between tests, which is all the
     * isolation a connection ever gave; what a second connection would add
     * is nothing a test here asks for. A closed one reconnects by itself.
     *
     * @var array<string, Connection>
     */
    private static array $connections = [];

    public static function connect(string $dsn): Connection
    {
        return self::$connections[$dsn] ??= ConnectionFactory::fromDsn($dsn);
    }

    /**
     * The tables to empty, children before parents — computed once per
     * process from the schema's own foreign keys (2026-09-20).
     *
     * @var list<string>|null
     */
    private static ?array $order = null;

    /**
     * Emptying rather than recreating keeps the migrated schema, including
     * the constraints that are half the point of these tests.
     *
     * **`DELETE`, not `TRUNCATE`** (2026-09-20). `TRUNCATE … CASCADE` is the
     * obvious statement and it was the one used until a full run took twenty
     * minutes on the operator's machine against three in CI: TRUNCATE gives
     * every table and index a new file, and on Windows a file is expensive —
     * two to four seconds for the forty-odd tables and their indexes, *per
     * test*, whether or not they held a row. A `DELETE` on a table with a
     * handful of rows is a few hundred microseconds anywhere.
     *
     * What is emptied is exactly what `TRUNCATE … CASCADE` emptied: the named
     * tables and everything that references them, transitively, in an order
     * the foreign keys accept. Sequences are restarted, which is what
     * `RESTART IDENTITY` did. A schema the order cannot handle — a cycle, a
     * `RESTRICT` self-reference — falls back to the slow, certain statement
     * rather than to a test failing on a constraint the fixture never wrote.
     */
    public static function reset(Connection $connection): void
    {
        $order = self::$order ??= self::deletionOrder($connection);

        if ($order === []) {
            self::truncate($connection);

            return;
        }

        $guarded = self::$guarded ??= self::guardedTables($connection, $order);

        try {
            $connection->transactional(static function (Connection $c) use ($order, $guarded): void {
                // The schema's own guards — the platform keeps an administrator,
                // an issued invoice is not deleted — are exactly right in
                // production and exactly wrong between two tests. TRUNCATE never
                // fired them; a DELETE would, so they are off for this
                // transaction. `USER` triggers only: the foreign keys stay on.
                foreach ($guarded as $table) {
                    $c->executeStatement(sprintf('ALTER TABLE "%s" DISABLE TRIGGER USER', $table));
                }

                foreach ($order as $table) {
                    $c->executeStatement(sprintf('DELETE FROM "%s"', $table));
                }

                foreach ($guarded as $table) {
                    $c->executeStatement(sprintf('ALTER TABLE "%s" ENABLE TRIGGER USER', $table));
                }

                foreach ($c->fetchFirstColumn(self::SEQUENCES) as $sequence) {
                    if (is_string($sequence)) {
                        $c->executeStatement(sprintf('ALTER SEQUENCE "%s" RESTART WITH 1', $sequence));
                    }
                }
            });
        } catch (\Throwable) {
            self::$order = null;
            self::$guarded = null;
            self::truncate($connection);
        }
    }

    /**
     * The emptied tables that carry a trigger of the schema's own, computed
     * once per process.
     *
     * @var list<string>|null
     */
    private static ?array $guarded = null;

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private static function guardedTables(Connection $connection, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $guarded = [];

        foreach ($connection->fetchFirstColumn(self::USER_TRIGGERS) as $table) {
            if (is_string($table) && in_array($table, $tables, true)) {
                $guarded[] = $table;
            }
        }

        return $guarded;
    }

    private const USER_TRIGGERS = <<<'SQL'
        SELECT DISTINCT c.relname
          FROM pg_trigger t
          JOIN pg_class c ON c.oid = t.tgrelid
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE NOT t.tgisinternal AND n.nspname = 'public'
        SQL;

    private static function truncate(Connection $connection): void
    {
        $connection->executeStatement('TRUNCATE ' . self::TABLES . ' RESTART IDENTITY CASCADE');
    }

    /** Every sequence in the schema, identity columns' included — which `information_schema.sequences` leaves out. */
    private const SEQUENCES = <<<'SQL'
        SELECT c.relname
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE c.relkind = 'S' AND n.nspname = 'public'
        SQL;

    /**
     * The named tables and everything that references them, transitively,
     * children first. Empty when the keys form a cycle the order cannot
     * satisfy, which the caller answers with the statement that can.
     *
     * @return list<string>
     */
    private static function deletionOrder(Connection $connection): array
    {
        $named = array_map(trim(...), explode(',', self::TABLES));

        /** @var list<array{child: string, parent: string}> $edges */
        $edges = [];

        foreach ($connection->fetchAllAssociative(self::FOREIGN_KEYS) as $row) {
            $child = $row['child'] ?? null;
            $parent = $row['parent'] ?? null;

            if (is_string($child) && is_string($parent)) {
                $edges[] = ['child' => $child, 'parent' => $parent];
            }
        }

        // What CASCADE reached: the named tables, and whatever points at them.
        /** @var array<string, true> $reached */
        $reached = array_fill_keys($named, true);
        $queue = $named;

        while ($queue !== []) {
            $table = array_pop($queue);

            foreach ($edges as $edge) {
                if ($edge['parent'] === $table && !isset($reached[$edge['child']])) {
                    $reached[$edge['child']] = true;
                    $queue[] = $edge['child'];
                }
            }
        }

        // Kahn's ordering with the edge reversed: a parent is ready once every
        // child that references it is gone. A self-reference is ignored — a
        // table deleted whole in one statement satisfies its own keys.
        /** @var array<string, int> $waitingOn */
        $waitingOn = array_fill_keys(array_keys($reached), 0);
        /** @var array<string, list<string>> $parentsOf */
        $parentsOf = [];

        foreach ($edges as $edge) {
            if ($edge['child'] !== $edge['parent'] && isset($reached[$edge['child']], $reached[$edge['parent']])) {
                ++$waitingOn[$edge['parent']];
                $parentsOf[$edge['child']][] = $edge['parent'];
            }
        }

        $ready = array_keys(array_filter($waitingOn, static fn (int $count): bool => $count === 0));
        sort($ready);
        $order = [];

        while ($ready !== []) {
            $table = array_shift($ready);
            $order[] = $table;

            foreach ($parentsOf[$table] ?? [] as $parent) {
                if (--$waitingOn[$parent] === 0) {
                    $ready[] = $parent;
                }
            }
        }

        return count($order) === count($reached) ? $order : [];
    }

    private const FOREIGN_KEYS = <<<'SQL'
        SELECT child.relname AS child, parent.relname AS parent
          FROM pg_constraint con
          JOIN pg_class child ON child.oid = con.conrelid
          JOIN pg_class parent ON parent.oid = con.confrelid
          JOIN pg_namespace n ON n.oid = child.relnamespace
         WHERE con.contype = 'f' AND n.nspname = 'public'
        SQL;

    /**
     * Gives a tenant a product, as the platform would (ADR-047).
     *
     * A membership must sit inside an assignment — the foreign key says so —
     * so every fixture that writes one calls this first. Idempotent, because
     * fixtures add several members of one tenant and the second call must
     * not be the one that fails.
     */
    public static function assignProduct(Connection $connection, string $tenantId, string $productId): void
    {
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_products (tenant_id, product_id)
                VALUES (:tenant, :product)
                ON CONFLICT DO NOTHING
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );
    }
}
