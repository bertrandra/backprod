<?php

declare(strict_types=1);

namespace App\Navigation\Infrastructure;

use App\Navigation\Domain\NavigationProbe;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

/**
 * Whether a list has rows, one `EXISTS` per entry, in one round trip.
 *
 * The map below is the whole of what the platform knows about the shell's
 * entry ids: which of them is a list of the reader's own rows, and which
 * table that list reads. An id absent from the map is never reported
 * empty. Adding a screen that lists something means adding a line here, or
 * the "hide what is empty" option quietly does nothing for it — which is
 * the safe failure, since an entry shown is recoverable and one hidden is
 * a capability the person cannot find.
 *
 * `EXISTS`, not `count(*)`: the question is whether there is one row, and a
 * count walks every row to say so. Jobs are scoped to the tenant alone,
 * because a job may belong to a tenant and no product.
 */
final class PostgresNavigationProbe implements NavigationProbe
{
    /** Entry id => the EXISTS clause, over `t` = tenant_id and `p` = product_id. */
    private const TENANT = [
        'projects' => 'SELECT 1 FROM projects WHERE tenant_id = :t AND product_id = :p AND deleted_at IS NULL',
        'jobs' => 'SELECT 1 FROM jobs WHERE tenant_id = :t',
        'quotes' => 'SELECT 1 FROM quotes WHERE tenant_id = :t AND product_id = :p',
        'orders' => 'SELECT 1 FROM orders WHERE tenant_id = :t AND product_id = :p',
        // Any subscription, live or not: a lapsed one is still something to
        // show, and "nothing" means nothing was ever taken out.
        'subscription' => 'SELECT 1 FROM subscriptions WHERE tenant_id = :t AND product_id = :p',
        'invoices' => 'SELECT 1 FROM invoices WHERE tenant_id = :t AND product_id = :p',
        'payments' => 'SELECT 1 FROM payments WHERE tenant_id = :t AND product_id = :p',
        'credit-notes' => 'SELECT 1 FROM credit_notes WHERE tenant_id = :t AND product_id = :p',
        // VAT periods read the tenant's whole fiscal history (2026-09-17),
        // so the list is empty until the first fact is booked in any product.
        'tax-reports' => 'SELECT 1 FROM vat_transactions WHERE tenant_id = :t',
        'conversations' => 'SELECT 1 FROM conversations WHERE tenant_id = :t AND product_id = :p',
        'notifications' => 'SELECT 1 FROM notifications WHERE tenant_id = :t AND product_id = :p',
    ];

    private const PLATFORM = [
        'tenants' => 'SELECT 1 FROM tenants',
        'threads' => "SELECT 1 FROM conversations WHERE kind = 'SUPPORT'",
        'directory' => 'SELECT 1 FROM users',
        'queue' => 'SELECT 1 FROM jobs',
        'audit' => 'SELECT 1 FROM audit_log',
        'access-log' => 'SELECT 1 FROM staff_access_log',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function emptyForTenant(string $tenantId, string $productId): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        return $this->empty(self::TENANT, ['t' => $tenantId, 'p' => $productId]);
    }

    public function emptyForPlatform(): array
    {
        return $this->empty(self::PLATFORM, []);
    }

    /**
     * @param array<string, string> $clauses
     * @param array<string, string> $parameters
     *
     * @return list<string>
     */
    private function empty(array $clauses, array $parameters): array
    {
        $columns = [];

        foreach ($clauses as $id => $clause) {
            // The alias is the entry id with hyphens folded, quoted, so the
            // row reads back by the same word the map is keyed on.
            $columns[] = sprintf('EXISTS (%s) AS "%s"', $clause, $id);
        }

        $row = $this->connection->fetchAssociative('SELECT ' . implode(', ', $columns), $parameters);

        if ($row === false) {
            return [];
        }

        $empty = [];

        foreach (array_keys($clauses) as $id) {
            if (($row[$id] ?? true) === false) {
                $empty[] = $id;
            }
        }

        return $empty;
    }
}
