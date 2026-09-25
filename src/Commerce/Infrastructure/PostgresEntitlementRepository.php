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
                   AND CASE
                         WHEN CAST(:userId AS uuid) IS NULL
                           THEN s.subscriber_kind = 'USER'
                         ELSE s.owner_user_id IS DISTINCT FROM CAST(:userId AS uuid)
                              AND s.subscriber_user_id IS DISTINCT FROM CAST(:userId AS uuid)
                              AND NOT EXISTS (
                                    SELECT 1 FROM subscription_members m
                                     WHERE m.subscription_id = s.id
                                       AND m.user_id = CAST(:userId AS uuid)
                                  )
                       END
              )
        SQL;

    /**
     * Why that NOT EXISTS is written the way it is (§13.1).
     *
     * It excludes an entitlement whose subscription is **not this person's**:
     * neither owned by them, nor addressed to them, nor listing them among
     * the people the owner added. Everything else survives — an override has
     * no subscription at all, so the subquery finds nothing and the row is
     * kept.
     *
     * **Amended 2026-09-25, and this is the rule that changed.** Until today
     * the exclusion began `s.subscriber_kind = 'USER'`, so it applied to
     * seats alone and an organisation's subscription entitled *every member*
     * of that organisation. The operator found what that means: they added a
     * person to Acme, and that person — on no subscription, holding no seat —
     * received all eleven of Pro's capabilities and could read every project.
     *
     * It also made the `users` quota decorative for an organisation's
     * subscription. Acme's Plan subscription sells three people and listed
     * none; the quota bounded a list nobody was on, while entitlement came
     * from membership regardless. A number somebody pays for has to bound
     * something.
     *
     * So the kind of subscriber no longer decides who is covered. **Buying
     * is what covers people**: the person who subscribed, plus those they
     * add within the number their offer sells (§13.1).
     *
     * The CASE keeps the **tenant-wide** question exactly as it was. With
     * nobody named, the answer is what the organisation bought — its own
     * subscriptions, and no seat, because one person's seat is not the
     * tenant's. That answer is what usage is measured against and what the
     * console shows, and neither is about any one person. Without the CASE,
     * `IS DISTINCT FROM NULL` is true of every subscription and the
     * tenant-wide answer would have silently emptied.
     *
     * IS DISTINCT FROM rather than <> throughout, so a null on either side
     * reads as "not this person" instead of collapsing the condition to
     * null — a subscription with no owner recorded covers nobody rather
     * than everybody. This fails closed, which is the direction to fail in.
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
     * Whether this person is on a subscription for this product
     * (2026-09-25).
     *
     * Against the same clock as everything else here: a subscription whose
     * period has ended covers nobody the morning after, whether or not a job
     * has run to notice. The three ways to be on one are the three the
     * exclusion above tests, said positively — owner, named subscriber, or
     * added by the owner.
     *
     * Asked of the **subscription**, and not through the entitlements it
     * produced. An earlier draft joined through `entitlements` to reuse this
     * class's one clock, which was tidy and wrong: it made coverage depend
     * on whether the offer happens to grant any features. An offer that
     * sells access and no feature writes no entitlement row, so its buyer
     * would have been covered by nothing on the work they had just paid
     * for — found by `SalesChainTest`, whose offer grants none.
     *
     * Coverage is about being on a subscription. What that subscription
     * *grants* is the other two queries' question.
     *
     * The clock is therefore the subscription's own, in the words
     * {@see \App\Commerce\Domain\Subscription::isLiveAt()} uses: active, and
     * inside its paid period. A null period end is open-ended, not expired —
     * a `CUSTOM` billing period has no computable end.
     *
     * An **override** is excluded by construction, since it has no
     * subscription at all: staff grant a feature to an organisation, never a
     * place on a subscription. Answered any other way, one support exception
     * would cover every member — the shape of the hole this closes.
     */
    public function covers(string $tenantId, string $productId, string $userId): bool
    {
        if (!self::addressable($tenantId, $productId) || !Uuid::isValid($userId)) {
            return false;
        }

        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                      FROM subscriptions s
                     WHERE s.tenant_id = CAST(:tenantId AS uuid)
                       AND s.product_id = CAST(:productId AS uuid)
                       AND s.status = 'ACTIVE'
                       AND (s.current_period_end IS NULL OR s.current_period_end > now())
                       AND (
                             s.owner_user_id = CAST(:userId AS uuid)
                             OR s.subscriber_user_id = CAST(:userId AS uuid)
                             OR EXISTS (
                                  SELECT 1 FROM subscription_members m
                                   WHERE m.subscription_id = s.id
                                     AND m.user_id = CAST(:userId AS uuid)
                                )
                           )
                )
                OR EXISTS (
                    -- A trial the platform opened (2026-09-25). It names no
                    -- people, and does not need to: the caller reached this
                    -- product through a membership of this tenant, which the
                    -- context resolved before anything here ran. What it
                    -- names is the decision — `covers_people` — because a
                    -- grant that only adds a feature must still cover nobody.
                    SELECT 1
                      FROM entitlements e
                     WHERE e.tenant_id = CAST(:tenantId AS uuid)
                       AND e.product_id = CAST(:productId AS uuid)
                       AND e.source = 'GRANT'
                       AND e.covers_people
                       AND e.valid_from <= now()
                       AND (e.valid_until IS NULL OR e.valid_until > now())
                )
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        ) === true;
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
