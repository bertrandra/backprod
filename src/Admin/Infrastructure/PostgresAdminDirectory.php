<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\AdminDirectory;
use App\Admin\Domain\DirectoryPage;
use Doctrine\DBAL\Connection;

/**
 * The operational listings, read straight.
 *
 * Two decisions run through all five.
 *
 * **The counts are subqueries, not joins.** A tenant with three
 * subscriptions and two unpaid invoices would come back six times from a
 * join, and `count(DISTINCT …)` over a fan-out is the kind of arithmetic that
 * is right until somebody adds a third join. A scalar subquery per column
 * cannot fan out.
 *
 * **A filter that was not given adds no condition**, expressed as
 * `(CAST(:x AS text) IS NULL OR col = CAST(:x AS text))`. Building the WHERE
 * clause by string concatenation is how a filter becomes an injection;
 * binding a null and letting the planner drop the branch keeps one statement
 * with one shape.
 *
 * **Every one of those casts is load-bearing.** A bare `:x IS NULL` gives
 * PostgreSQL nothing to infer the parameter's type from, and it refuses the
 * statement outright — `could not determine data type of parameter $1`, at
 * prepare time, on every call rather than only on the filtered ones. All five
 * listings were written without them and all five failed against a real
 * server.
 *
 * Nothing here reads a tenant's *content*. These are records about tenant
 * data — that a subscription exists, that an invoice was raised — which is
 * the line non-negotiable #19 draws between operating the platform and
 * reading what its customers put into it.
 */
final class PostgresAdminDirectory implements AdminDirectory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function tenants(?string $search, int $limit, int $offset): DirectoryPage
    {
        $where = '(CAST(:search AS text) IS NULL'
            . ' OR t.name ILIKE CAST(:pattern AS text) OR t.slug ILIKE CAST(:pattern AS text))';
        $parameters = ['search' => $search, 'pattern' => self::pattern($search)];

        return $this->page(
            <<<SQL
                SELECT t.id, t.name, t.slug, t.created_at,
                       (SELECT count(DISTINCT tm.user_id) FROM tenant_members tm
                         WHERE tm.tenant_id = t.id) AS members,
                       (SELECT count(*) FROM subscriptions s
                         WHERE s.tenant_id = t.id AND s.status = 'ACTIVE') AS active_subscriptions,
                       (SELECT count(*) FROM invoices i
                         WHERE i.tenant_id = t.id AND i.status = 'ISSUED') AS unpaid_invoices
                  FROM tenants t
                 WHERE {$where}
                 ORDER BY t.created_at DESC, t.id
                SQL,
            "SELECT count(*) FROM tenants t WHERE {$where}",
            $parameters,
            $limit,
            $offset,
        );
    }

    public function users(?string $search, int $limit, int $offset): DirectoryPage
    {
        // An erased row cannot match a search on identity it no longer has —
        // email and display_name are NULL after erasure — so the search
        // narrows to people who can still be named, and the listing without a
        // search still shows everyone.
        $where = '(CAST(:search AS text) IS NULL'
            . ' OR u.email ILIKE CAST(:pattern AS text) OR u.display_name ILIKE CAST(:pattern AS text))';
        $parameters = ['search' => $search, 'pattern' => self::pattern($search)];

        return $this->page(
            <<<SQL
                SELECT u.id, u.email, u.display_name, u.created_at, u.erased_at,
                       (SELECT count(DISTINCT tm.tenant_id) FROM tenant_members tm
                         WHERE tm.user_id = u.id) AS tenants
                  FROM users u
                 WHERE {$where}
                 ORDER BY u.created_at DESC, u.id
                SQL,
            "SELECT count(*) FROM users u WHERE {$where}",
            $parameters,
            $limit,
            $offset,
        );
    }

    public function subscriptions(?string $tenantId, ?string $productId, ?string $status, int $limit, int $offset): DirectoryPage
    {
        $where = '(CAST(:tenantId AS uuid) IS NULL OR s.tenant_id = CAST(:tenantId AS uuid))'
            . ' AND (CAST(:productId AS uuid) IS NULL OR s.product_id = CAST(:productId AS uuid))'
            . ' AND (CAST(:status AS text) IS NULL OR s.status = CAST(:status AS text))';
        $parameters = ['tenantId' => $tenantId, 'productId' => $productId, 'status' => $status];

        return $this->page(
            <<<SQL
                SELECT s.id, s.tenant_id, t.name AS tenant_name, s.product_id, p.code AS product_code,
                       s.status,
                       s.started_at, s.current_period_end, s.cancel_at_period_end,
                       s.term_ends_at, s.commitment_ends_at,
                       o.code AS offer_code, ov.version AS offer_version,
                       ov.price_minor_units, ov.currency,
                       -- Who contracted, and who (2026-09-26). Since ADR-055
                       -- every subscription the tenant surface sells is a
                       -- seat held by one person, and a console that named
                       -- only the organisation was describing the world as it
                       -- was before that: three seats in Acme looked like
                       -- three identical rows.
                       s.subscriber_kind, s.owner_user_id,
                       hu.display_name AS holder_name, hu.email AS holder_email
                  FROM subscriptions s
                  JOIN tenants t ON t.id = s.tenant_id
                  JOIN products p ON p.id = s.product_id
                  JOIN offer_versions ov ON ov.id = s.offer_version_id
                  JOIN offers o ON o.id = ov.offer_id
                  -- LEFT: a row from before ownership was recorded has no
                  -- owner, and dropping it would hide a subscription rather
                  -- than show it unattributed.
                  LEFT JOIN users hu ON hu.id = s.owner_user_id
                 WHERE {$where}
                 ORDER BY s.started_at DESC, s.id
                SQL,
            "SELECT count(*) FROM subscriptions s WHERE {$where}",
            $parameters,
            $limit,
            $offset,
        );
    }

    public function invoices(?string $tenantId, ?string $productId, ?string $status, int $limit, int $offset): DirectoryPage
    {
        $where = '(CAST(:tenantId AS uuid) IS NULL OR i.tenant_id = CAST(:tenantId AS uuid))'
            . ' AND (CAST(:productId AS uuid) IS NULL OR i.product_id = CAST(:productId AS uuid))'
            . ' AND (CAST(:status AS text) IS NULL OR i.status = CAST(:status AS text))';
        $parameters = ['tenantId' => $tenantId, 'productId' => $productId, 'status' => $status];

        return $this->page(
            <<<SQL
                SELECT i.id, i.number, i.tenant_id, t.name AS tenant_name,
                       i.product_id, p.code AS product_code, i.status,
                       i.currency, i.net_minor_units, i.vat_minor_units, i.gross_minor_units,
                       i.issued_at, i.due_at, i.paid_at,
                       -- Who the document names, read from the snapshots it
                       -- keeps rather than from a live row (§25): that is what
                       -- the customer received, and a name changed since must
                       -- not change the invoice. For a seat these differ from
                       -- the tenant — Acme sold it, Ada bought it (ADR-055).
                       i.supplier_snapshot->>'legal_name' AS supplier_name,
                       i.customer_snapshot->>'legal_name' AS customer_name,
                       i.customer_snapshot->>'billing_email' AS customer_email,
                       -- Whose gapless series the number came from (ADR-054);
                       -- null is the platform's own.
                       i.issuer_tenant_id
                  FROM invoices i
                  JOIN tenants t ON t.id = i.tenant_id
                  JOIN products p ON p.id = i.product_id
                 WHERE {$where}
                 ORDER BY i.issued_at DESC NULLS LAST, i.id
                SQL,
            "SELECT count(*) FROM invoices i WHERE {$where}",
            $parameters,
            $limit,
            $offset,
        );
    }

    public function jobs(?string $status, ?string $type, int $limit, int $offset): DirectoryPage
    {
        $where = '(CAST(:status AS text) IS NULL OR j.status = CAST(:status AS text))'
            . ' AND (CAST(:type AS text) IS NULL OR j.type = CAST(:type AS text))';
        $parameters = ['status' => $status, 'type' => $type];

        // The payload is deliberately not selected. It is whatever the caller
        // handed the queue, it can carry anything, and this is a cross-tenant
        // screen — "which jobs are stuck" does not need to know what is in
        // them.
        return $this->page(
            <<<SQL
                SELECT j.id, j.type, j.status, j.tenant_id, j.product_id, j.priority,
                       j.attempts, j.max_attempts, j.run_after, j.leased_until,
                       j.failure_reason, j.started_at, j.finished_at, j.created_at
                  FROM jobs j
                 WHERE {$where}
                 ORDER BY j.created_at DESC, j.id
                SQL,
            "SELECT count(*) FROM jobs j WHERE {$where}",
            $parameters,
            $limit,
            $offset,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return DirectoryPage<array<string, mixed>>
     */
    private function page(
        string $rowsSql,
        string $countSql,
        array $parameters,
        int $limit,
        int $offset,
    ): DirectoryPage {
        // LIMIT and OFFSET are interpolated from ints the caller has already
        // bounded, never from request text: PostgreSQL will not take a bound
        // parameter in either position through every driver path, and an int
        // that has been through `PageRequest::bounded` cannot carry anything
        // but digits.
        $rows = $this->connection->fetchAllAssociative(
            $rowsSql . sprintf(' LIMIT %d OFFSET %d', $limit, $offset),
            $parameters,
        );

        $total = $this->connection->fetchOne($countSql, $parameters);

        return new DirectoryPage(
            array_values($rows),
            is_int($total) ? $total : (int) (is_string($total) ? $total : 0),
            $limit,
            $offset,
        );
    }

    private static function pattern(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        // The wildcards are ours; the caller's own % and _ are escaped so a
        // search for "50%" is a search for "50%" and not for everything.
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    }
}
