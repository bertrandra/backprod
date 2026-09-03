<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Domain\SubscriptionRepository;
use App\Shared\Database\Row;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use stdClass;

/**
 * Subscriptions in PostgreSQL.
 *
 * Every state change here does three things together — move the
 * subscription, record why, and rewrite what the tenant may use — and all
 * three happen in one transaction. A subscription observable without its
 * entitlements would mean a tenant paying for capabilities they cannot use;
 * entitlements without their event would mean access nobody can account for.
 *
 * The entitlement rows are derived state, rebuilt from the offer version on
 * every change. The audit trail is subscription_events, which is append-only
 * and never rewritten — so discarding a derived row loses nothing that
 * non-negotiable #18 asks to be kept.
 */
final class PostgresSubscriptionRepository implements SubscriptionRepository
{
    private const COLUMNS = <<<'SQL'
        s.id, s.tenant_id, s.product_id, s.offer_version_id, s.status,
        s.started_at, s.current_period_start, s.current_period_end,
        s.cancel_at_period_end, s.cancelled_at, s.ended_at,
        o.id AS offer_id, o.code AS offer_code, o.name AS offer_name,
        pl.id AS plan_id, pl.code AS plan_code, pl.name AS plan_name, pl.rank AS plan_rank,
        v.version, v.status AS version_status, v.billing_period,
        v.price_minor_units, v.currency, v.valid_from, v.valid_until
        SQL;

    private const FROM = <<<'SQL'
        FROM subscriptions s
        JOIN offer_versions v ON v.id = s.offer_version_id
        JOIN offers o ON o.id = v.offer_id
        JOIN plans pl ON pl.id = o.plan_id
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findActive(string $tenantId, string $productId): ?Subscription
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . <<<'SQL'
                 WHERE s.tenant_id = :tenantId
                   AND s.product_id = :productId
                   AND s.status = 'ACTIVE'
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return $row === false ? null : $this->toSubscription($row);
    }

    public function history(string $tenantId, string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . <<<'SQL'
                 WHERE s.tenant_id = :tenantId AND s.product_id = :productId
                 ORDER BY s.started_at DESC, s.id
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return array_map($this->toSubscription(...), $rows);
    }

    public function activate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): Subscription {
        return $this->connection->transactional(
            fn (): Subscription => $this->applyActivate($tenantId, $productId, $offer, $periodEnd, $actorUserId),
        );
    }

    public function applyActivate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): Subscription {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, current_period_end)
                VALUES (:tenantId, :productId, :versionId, :periodEnd)
                RETURNING id
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'versionId' => $offer->version->id,
                'periodEnd' => self::moment($periodEnd),
            ],
        );

        if (!is_string($id)) {
            throw new RuntimeException('Failed to start a subscription.');
        }

        $this->record($id, SubscriptionEvent::ACTIVATED, null, $offer->version->id, $actorUserId, []);
        $this->grantEntitlements($id, $tenantId, $productId, $offer, $periodEnd);

        return $this->requireActive($tenantId, $productId);
    }

    public function changeOffer(
        Subscription $subscription,
        SubscribedOffer $offer,
        string $direction,
        ?string $actorUserId,
    ): Subscription {
        return $this->connection->transactional(
            function () use ($subscription, $offer, $direction, $actorUserId): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET offer_version_id = :versionId, updated_at = now()
                         WHERE id = :id
                        SQL,
                    ['versionId' => $offer->version->id, 'id' => $subscription->id],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::OFFER_CHANGED,
                    $subscription->offer->version->id,
                    $offer->version->id,
                    $actorUserId,
                    ['direction' => $direction],
                );

                // The old grants go, the new ones arrive, and the period is
                // untouched: what the tenant may use changes, what they have
                // paid for until does not. Prorating is billing (M6).
                $this->revokeEntitlements($subscription->id);
                $this->grantEntitlements(
                    $subscription->id,
                    $subscription->tenantId,
                    $subscription->productId,
                    $offer,
                    $subscription->currentPeriodEnd,
                );

                return $this->requireActive($subscription->tenantId, $subscription->productId);
            },
        );
    }

    public function cancel(Subscription $subscription, bool $immediately, ?string $actorUserId): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $immediately, $actorUserId): Subscription {
                if (!$immediately) {
                    // Still live, still entitled: a customer who cancels on
                    // day two of a month they paid for keeps the month. The
                    // entitlements already end when the period does.
                    $this->connection->executeStatement(
                        <<<'SQL'
                            UPDATE subscriptions
                               SET cancel_at_period_end = true, cancelled_at = now(), updated_at = now()
                             WHERE id = :id
                            SQL,
                        ['id' => $subscription->id],
                    );

                    $this->record(
                        $subscription->id,
                        SubscriptionEvent::CANCELLATION_SCHEDULED,
                        $subscription->offer->version->id,
                        null,
                        $actorUserId,
                        [],
                    );

                    return $this->requireActive($subscription->tenantId, $subscription->productId);
                }

                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET status = 'CANCELLED', cancel_at_period_end = false,
                               cancelled_at = now(), ended_at = now(), updated_at = now()
                         WHERE id = :id
                        SQL,
                    ['id' => $subscription->id],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::CANCELLED,
                    $subscription->offer->version->id,
                    null,
                    $actorUserId,
                    ['immediate' => true],
                );

                $this->endEntitlements($subscription->id);

                return $this->latest($subscription->id);
            },
        );
    }

    public function resume(Subscription $subscription, ?string $actorUserId): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $actorUserId): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET cancel_at_period_end = false, cancelled_at = NULL, updated_at = now()
                         WHERE id = :id AND status = 'ACTIVE'
                        SQL,
                    ['id' => $subscription->id],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::RESUMED,
                    $subscription->offer->version->id,
                    null,
                    $actorUserId,
                    [],
                );

                return $this->requireActive($subscription->tenantId, $subscription->productId);
            },
        );
    }

    public function renew(Subscription $subscription, ?DateTimeImmutable $periodEnd): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $periodEnd): Subscription {
                // The new period starts where the old one ended, not at now():
                // renewing an hour late must not leave the tenant unentitled
                // for that hour, nor silently shorten what they paid for.
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET current_period_start = coalesce(current_period_end, now()),
                               current_period_end = :periodEnd,
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                    ['periodEnd' => self::moment($periodEnd), 'id' => $subscription->id],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::RENEWED,
                    $subscription->offer->version->id,
                    $subscription->offer->version->id,
                    null,
                    [],
                );

                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE entitlements
                           SET valid_until = :periodEnd, updated_at = now()
                         WHERE subscription_id = :id AND source = 'SUBSCRIPTION'
                        SQL,
                    ['periodEnd' => self::moment($periodEnd), 'id' => $subscription->id],
                );

                return $this->requireActive($subscription->tenantId, $subscription->productId);
            },
        );
    }

    public function events(Subscription $subscription): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, type, from_offer_version_id, to_offer_version_id,
                       actor_user_id, detail::text AS detail, occurred_at
                  FROM subscription_events
                 WHERE subscription_id = :id
                 ORDER BY occurred_at DESC, id
                SQL,
            ['id' => $subscription->id],
        );

        return array_map(
            static function (array $row): SubscriptionEvent {
                $detail = json_decode(Row::string($row, 'detail'), false);

                return new SubscriptionEvent(
                    Row::string($row, 'id'),
                    Row::string($row, 'type'),
                    Row::nullableString($row, 'from_offer_version_id'),
                    Row::nullableString($row, 'to_offer_version_id'),
                    Row::nullableString($row, 'actor_user_id'),
                    $detail instanceof stdClass ? $detail : new stdClass(),
                    Row::timestamp($row, 'occurred_at'),
                );
            },
            $rows,
        );
    }

    public function expireLapsed(): int
    {
        return $this->connection->transactional(function (): int {
            // RETURNING rather than a bulk UPDATE, because every other
            // transition in this repository writes an event and this one is
            // not special. A subscription that ended with no trace of ending
            // would be the one gap in an otherwise complete history (§18).
            //
            // A CUSTOM period has no `current_period_end` and is therefore
            // never past one — untouched, deliberately.
            $ids = $this->connection->fetchFirstColumn(
                <<<'SQL'
                    UPDATE subscriptions
                       SET status = 'EXPIRED', ended_at = now(), updated_at = now()
                     WHERE status = 'ACTIVE'
                       AND current_period_end IS NOT NULL
                       AND current_period_end < now()
                    RETURNING id
                    SQL,
            );

            foreach ($ids as $id) {
                if (is_string($id)) {
                    // No actor: nobody did this, the clock did.
                    $this->record($id, SubscriptionEvent::EXPIRED, null, null, null, []);
                }
            }

            return count($ids);
        });
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(
        string $subscriptionId,
        string $type,
        ?string $fromVersionId,
        ?string $toVersionId,
        ?string $actorUserId,
        array $detail,
    ): void {
        $encoded = json_encode($detail === [] ? new stdClass() : $detail);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO subscription_events
                    (subscription_id, type, from_offer_version_id, to_offer_version_id, actor_user_id, detail)
                VALUES (:id, :type, :fromVersion, :toVersion, :actor, CAST(:detail AS jsonb))
                SQL,
            [
                'id' => $subscriptionId,
                'type' => $type,
                'fromVersion' => $fromVersionId,
                'toVersion' => $toVersionId,
                'actor' => $actorUserId,
                'detail' => $encoded === false ? '{}' : $encoded,
            ],
        );
    }

    /**
     * Writes one entitlement per grant, ending when the period does.
     *
     * The window is what makes expiry automatic: nothing has to run at
     * midnight for a lapsed subscription to stop granting things.
     */
    private function grantEntitlements(
        string $subscriptionId,
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
    ): void {
        foreach ($offer->version->grants as $grant) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO entitlements
                        (tenant_id, product_id, feature_id, limit_value, source, subscription_id, valid_until)
                    VALUES (:tenantId, :productId, :featureId, :limit, 'SUBSCRIPTION', :subscriptionId, :validUntil)
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'productId' => $productId,
                    'featureId' => $grant->feature->id,
                    'limit' => $grant->limit,
                    'subscriptionId' => $subscriptionId,
                    'validUntil' => self::moment($periodEnd),
                ],
            );
        }
    }

    /**
     * Only the rows this subscription produced. An OVERRIDE is a deliberate
     * human grant and must survive a plan change — otherwise a support
     * team's exception disappears the next time the customer upgrades.
     */
    private function revokeEntitlements(string $subscriptionId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM entitlements WHERE subscription_id = :id AND source = 'SUBSCRIPTION'",
            ['id' => $subscriptionId],
        );
    }

    private function endEntitlements(string $subscriptionId): void
    {
        // GREATEST guards the window constraint: cancelling in the same
        // microsecond as subscribing would otherwise try to close a window
        // before it opened.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE entitlements
                   SET valid_until = GREATEST(now(), valid_from + interval '1 microsecond'),
                       updated_at = now()
                 WHERE subscription_id = :id
                   AND source = 'SUBSCRIPTION'
                   AND (valid_until IS NULL OR valid_until > now())
                SQL,
            ['id' => $subscriptionId],
        );
    }

    private function requireActive(string $tenantId, string $productId): Subscription
    {
        $subscription = $this->findActive($tenantId, $productId);

        if ($subscription === null) {
            throw new RuntimeException('The subscription vanished during the change that created it.');
        }

        return $subscription;
    }

    /**
     * Reads one by id regardless of status — used after a change that leaves
     * it no longer active, where findActive() would correctly return null.
     */
    private function latest(string $subscriptionId): Subscription
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE s.id = :id',
            ['id' => $subscriptionId],
        );

        if ($row === false) {
            throw new RuntimeException('The subscription vanished during a change to it.');
        }

        return $this->toSubscription($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSubscription(array $row): Subscription
    {
        $versionId = Row::string($row, 'offer_version_id');

        $version = new OfferVersion(
            $versionId,
            Row::integer($row, 'version'),
            Row::string($row, 'version_status'),
            Row::string($row, 'billing_period'),
            Row::integer($row, 'price_minor_units'),
            Row::string($row, 'currency'),
            Row::timestamp($row, 'valid_from'),
            Row::nullableTimestamp($row, 'valid_until'),
            $this->grantsOf($versionId),
        );

        return new Subscription(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            new SubscribedOffer(
                Row::string($row, 'offer_id'),
                Row::string($row, 'offer_code'),
                Row::string($row, 'offer_name'),
                new Plan(
                    Row::string($row, 'plan_id'),
                    Row::string($row, 'plan_code'),
                    Row::string($row, 'plan_name'),
                    Row::integer($row, 'plan_rank'),
                ),
                $version,
            ),
            Row::string($row, 'status'),
            Row::timestamp($row, 'started_at'),
            Row::timestamp($row, 'current_period_start'),
            Row::nullableTimestamp($row, 'current_period_end'),
            self::boolean($row, 'cancel_at_period_end'),
            Row::nullableTimestamp($row, 'cancelled_at'),
            Row::nullableTimestamp($row, 'ended_at'),
        );
    }

    /**
     * @return list<OfferGrant>
     */
    private function grantsOf(string $versionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT ovf.limit_value, f.id, f.code, f.name, f.kind, f.unit
                  FROM offer_version_features ovf
                  JOIN features f ON f.id = ovf.feature_id
                 WHERE ovf.offer_version_id = :id
                 ORDER BY f.code
                SQL,
            ['id' => $versionId],
        );

        return array_map(
            static fn (array $row): OfferGrant => new OfferGrant(
                new Feature(
                    Row::string($row, 'id'),
                    Row::string($row, 'code'),
                    Row::string($row, 'name'),
                    Row::string($row, 'kind'),
                    Row::nullableString($row, 'unit'),
                ),
                Row::nullableInteger($row, 'limit_value'),
            ),
            $rows,
        );
    }

    /**
     * PDO hands PostgreSQL booleans back as 't' and 'f'.
     *
     * @param array<string, mixed> $row
     */
    private static function boolean(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    private static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s.uP');
    }
}
