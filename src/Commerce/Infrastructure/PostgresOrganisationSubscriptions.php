<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Billing\Domain\Money;
use App\Commerce\Domain\HeldSubscription;
use App\Commerce\Domain\OrganisationSubscriptions;
use App\Commerce\Domain\Places;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

/**
 * The organisation's subscriptions, holders and places — one query.
 *
 * The two joins that earn their place:
 *
 * - **the holder**, `LEFT JOIN`ed on `owner_user_id`, because a row from
 *   before ownership was recorded has none and dropping it would hide a
 *   subscription rather than show it unattributed;
 * - **the places sold**, from the offer version's grant on the `users`
 *   feature, through a `LEFT JOIN LATERAL` so an offer that grants nothing
 *   still produces its row — an inner join would drop exactly the offers
 *   that sell one place, which are the ones the quota bites on.
 *
 * The grant is read as two facts, not one: whether it exists at all, and what
 * limit it carries. An offer granting no `users` feature covers its holder
 * alone, and one granting it with no limit covers everybody — the same `NULL`
 * in the database meaning opposite things. {@see Places::sold} is that rule,
 * and this only feeds it.
 */
final class PostgresOrganisationSubscriptions implements OrganisationSubscriptions
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function countOf(string $tenantId, string $productId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $total = $this->connection->fetchOne(
            'SELECT count(*) FROM subscriptions WHERE tenant_id = :tenantId AND product_id = :productId',
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return is_numeric($total) ? (int) $total : 0;
    }

    public function of(string $tenantId, string $productId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.id,
                       s.status,
                       s.subscriber_kind,
                       s.current_period_end,
                       s.owner_user_id,
                       u.display_name AS holder_name,
                       u.email        AS holder_email,
                       o.name  AS offer_name,
                       pl.name AS plan_name,
                       v.billing_period,
                       v.price_minor_units,
                       v.currency,
                       -- Coalesced here rather than read as a nullable: the
                       -- absence of a grant is a fact with a value (one
                       -- place), not a missing answer.
                       coalesce(g.granted, false) AS places_granted,
                       g.limit_value              AS places_limit,
                       (SELECT count(*) FROM subscription_members m WHERE m.subscription_id = s.id) AS members
                  FROM subscriptions s
                  JOIN offer_versions v ON v.id = s.offer_version_id
                  JOIN offers o         ON o.id = v.offer_id
                  JOIN plans pl         ON pl.id = o.plan_id
                  LEFT JOIN users u     ON u.id = s.owner_user_id
                  LEFT JOIN LATERAL (
                        SELECT true AS granted, f.limit_value
                          FROM offer_version_features f
                          JOIN features ft ON ft.id = f.feature_id
                         WHERE f.offer_version_id = v.id
                           AND ft.code = :usersFeature
                         LIMIT 1
                  ) g ON true
                 WHERE s.tenant_id = :tenantId
                   AND s.product_id = :productId
                 -- Living first, decided here rather than by the screen
                 -- (2026-09-26): the same clock `isLiveAt` uses, so a page
                 -- boundary cannot put a live seat below a cancelled one.
                 ORDER BY (s.status = 'ACTIVE'
                             AND (s.current_period_end IS NULL OR s.current_period_end > now())) DESC,
                          s.started_at DESC,
                          s.id
                 LIMIT :limit OFFSET :offset
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'usersFeature' => Places::USERS_FEATURE,
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        return array_map(
            static function (array $row): HeldSubscription {
                $owner = Row::nullableString($row, 'owner_user_id');
                $granted = Row::boolean($row, 'places_granted');

                return new HeldSubscription(
                    Row::string($row, 'id'),
                    Row::string($row, 'status'),
                    Row::string($row, 'subscriber_kind'),
                    $owner,
                    Row::nullableString($row, 'holder_name'),
                    Row::nullableString($row, 'holder_email'),
                    Row::string($row, 'offer_name'),
                    Row::string($row, 'plan_name'),
                    Row::string($row, 'billing_period'),
                    Money::of(Row::integer($row, 'price_minor_units'), Row::string($row, 'currency')),
                    Row::nullableTimestamp($row, 'current_period_end'),
                    // No grant at all sells one place: the holder. A grant
                    // with no limit sells everybody. Both are NULL in
                    // `limit_value`, so the flag is what tells them apart.
                    Places::sold($granted, Row::nullableInteger($row, 'places_limit')),
                    // The holder is one of the people the subscription
                    // covers, so they are counted — and a row with no owner
                    // counts only the people on it, because there is nobody
                    // else to count.
                    ($owner === null ? 0 : 1) + Row::integer($row, 'members'),
                );
            },
            $rows,
        );
    }
}
