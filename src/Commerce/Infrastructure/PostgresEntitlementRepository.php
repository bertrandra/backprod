<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Entitlement\Domain\Entitlement;
use App\Entitlement\Domain\EntitlementRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

/**
 * What a tenant may use, in PostgreSQL.
 *
 * Lives in the commerce module because commerce is what writes these rows,
 * but implements the entitlement module's port — so the §10.6 context chain
 * depends on a narrow interface rather than on the whole catalogue. The same
 * arrangement as ProductRepository against ProductRegistry in M3: the hot
 * path gets its own question, and the rest can grow without widening it.
 *
 * Every query filters on the clock. An entitlement carries the window it was
 * granted for, so a subscription that lapsed last night stops granting
 * anything this morning, whether or not a job has run to notice.
 */
final class PostgresEntitlementRepository implements EntitlementRepository
{
    /**
     * The window test, written once. valid_from is inclusive and valid_until
     * exclusive, matching the offer's commercial window — one convention for
     * "is this in force", not two.
     */
    private const IN_FORCE = <<<'SQL'
        e.tenant_id = :tenantId
          AND e.product_id = :productId
          AND e.valid_from <= now()
          AND (e.valid_until IS NULL OR e.valid_until > now())
          AND NOT EXISTS (
                SELECT 1
                  FROM subscriptions s
                 WHERE s.id = e.subscription_id
                   AND s.subscriber_kind = 'USER'
                   AND s.subscriber_user_id IS DISTINCT FROM CAST(:userId AS uuid)
              )
        SQL;

    /**
     * Why that NOT EXISTS is written the way it is (§13.1).
     *
     * It excludes exactly one thing: an entitlement whose subscription is a
     * seat belonging to somebody else. Everything else survives — an
     * override has no subscription at all, so the subquery finds nothing and
     * the row is kept.
     *
     * IS DISTINCT FROM rather than <> so a null $userId behaves correctly
     * instead of collapsing the whole condition to null: with nobody named,
     * every seat is somebody else's, and the tenant-wide answer contains
     * none of them. That is the honest reading of "what does this tenant
     * have" — one person's seat is not the tenant's.
     */

    /**
     * Where a tenant holds the same feature twice — a subscription grant and
     * a negotiated override, say — the most generous wins: an override
     * first, then unlimited, then the largest limit.
     *
     * Refusing to choose is not an option (the caller needs one answer), and
     * choosing the *smaller* would mean a support team's deliberate
     * exception silently did nothing.
     */
    private const MOST_GENEROUS_FIRST = <<<'SQL'
        f.code,
        (e.source = 'OVERRIDE') DESC,
        (e.limit_value IS NULL) DESC,
        e.limit_value DESC
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function capabilitiesFor(string $tenantId, string $productId, ?string $userId = null): array
    {
        if (!self::addressable($tenantId, $productId)) {
            return [];
        }

        // The §10.6 chain runs this on every authenticated request, so it
        // asks only what that chain needs: which codes are live.
        $codes = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT f.code FROM entitlements e
             JOIN features f ON f.id = e.feature_id
             WHERE ' . self::IN_FORCE . '
             ORDER BY f.code',
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        $capabilities = [];

        foreach ($codes as $code) {
            if (is_string($code)) {
                $capabilities[] = $code;
            }
        }

        return $capabilities;
    }

    public function entitlementsFor(string $tenantId, string $productId, ?string $userId = null): array
    {
        if (!self::addressable($tenantId, $productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (f.code)
                    f.code, f.name, f.kind, f.unit,
                    e.limit_value, e.source, e.valid_until
               FROM entitlements e
               JOIN features f ON f.id = e.feature_id
              WHERE ' . self::IN_FORCE . '
              ORDER BY ' . self::MOST_GENEROUS_FIRST,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        return array_map(
            static fn (array $row): Entitlement => new Entitlement(
                Row::string($row, 'code'),
                Row::string($row, 'name'),
                Row::string($row, 'kind'),
                Row::nullableString($row, 'unit'),
                Row::nullableInteger($row, 'limit_value'),
                Row::string($row, 'source'),
                Row::nullableTimestamp($row, 'valid_until'),
            ),
            $rows,
        );
    }

    /**
     * An id that cannot be a UUID matches no row, so it grants nothing.
     *
     * PostgreSQL raises on `= 'not-a-uuid'` against a UUID column, and this
     * runs on every authenticated request — the one place in the platform
     * where a driver error would take down every call rather than one. Both
     * ids come from the resolved context and are always well-formed, which
     * makes this defence in depth rather than input handling, and the same
     * guard the product, project and offer adapters carry.
     */
    private static function addressable(string $tenantId, string $productId): bool
    {
        return Uuid::isValid($tenantId) && Uuid::isValid($productId);
    }
}
