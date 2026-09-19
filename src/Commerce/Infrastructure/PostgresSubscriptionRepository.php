<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\RenewalNotice;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Domain\SubscriptionMember;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Domain\SubscriptionTerms;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
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
        s.subscriber_kind, s.subscriber_user_id,
        s.term_months, s.term_ends_at, s.commitment_months, s.commitment_ends_at,
        s.cancellation_policy, s.renewal, s.early_termination, s.notice_days,
        s.cancel_effective_at, s.owner_user_id,
        o.id AS offer_id, o.code AS offer_code, o.name AS offer_name,
        pl.id AS plan_id, pl.code AS plan_code, pl.name AS plan_name, pl.rank AS plan_rank,
        v.version, v.status AS version_status, v.billing_period,
        v.price_minor_units, v.currency, v.valid_from, v.valid_until,
        v.term_months AS version_term_months, v.commitment_months AS version_commitment_months,
        v.cancellation_policy AS version_cancellation_policy, v.renewal AS version_renewal,
        v.early_termination AS version_early_termination, v.notice_days AS version_notice_days
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
                   AND s.subscriber_kind = 'TENANT'
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
        ?Subscriber $subscriber = null,
    ): Subscription {
        return $this->connection->transactional(
            fn (): Subscription => $this->applyActivate(
                $tenantId,
                $productId,
                $offer,
                $periodEnd,
                $actorUserId,
                $subscriber,
            ),
        );
    }

    public function applyActivate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
        ?Subscriber $subscriber = null,
    ): Subscription {
        // The terms are copied from the version as values, not referenced.
        // Repricing or re-terming the offer tomorrow must not change one
        // condition this subscriber agreed to today — the invoice snapshot
        // rule (§25) applied to the contract (§13.1).
        $terms = $offer->version->terms;
        $startedAt = new DateTimeImmutable();
        $subscriber = $subscriber ?? Subscriber::tenant();

        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, current_period_end,
                     subscriber_kind, subscriber_user_id, owner_user_id,
                     term_months, term_ends_at, commitment_months, commitment_ends_at,
                     cancellation_policy, renewal, early_termination, notice_days)
                VALUES (:tenantId, :productId, :versionId, :periodEnd,
                        :subscriberKind, :subscriberUserId, :ownerUserId,
                        :termMonths, :termEndsAt, :commitmentMonths, :commitmentEndsAt,
                        :cancellationPolicy, :renewal, :earlyTermination, :noticeDays)
                RETURNING id
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'versionId' => $offer->version->id,
                'periodEnd' => self::moment($periodEnd),
                'subscriberKind' => $subscriber->kind,
                'subscriberUserId' => $subscriber->userId,
                // The owner (2026-09-19): the person a seat is for, else
                // whoever activated it — the administrator who bought the
                // organisation's, or nobody for a subscription a job started.
                'ownerUserId' => $subscriber->isSeat() ? $subscriber->userId : $actorUserId,
                'termMonths' => $terms->termMonths,
                'termEndsAt' => self::moment($terms->termEndsFrom($startedAt)),
                'commitmentMonths' => $terms->commitmentMonths,
                'commitmentEndsAt' => self::moment($terms->commitmentEndsFrom($startedAt)),
                'cancellationPolicy' => $terms->cancellationPolicy,
                'renewal' => $terms->renewal,
                'earlyTermination' => $terms->earlyTermination,
                'noticeDays' => $terms->noticeDays,
            ],
        );

        if (!is_string($id)) {
            throw new RuntimeException('Failed to start a subscription.');
        }

        $this->record($id, SubscriptionEvent::ACTIVATED, null, $offer->version->id, $actorUserId, []);
        $this->grantEntitlements($id, $tenantId, $productId, $offer, $periodEnd);

        // Fetched by id rather than by (tenant, product): a seat is not the
        // tenant's subscription, and asking for the tenant's would return
        // somebody else's row or none at all.
        $created = $this->findById($id);

        if ($created === null) {
            throw new RuntimeException('The subscription vanished during the transaction that created it.');
        }

        return $created;
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

    public function resume(Subscription $subscription, ?string $actorUserId): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $actorUserId): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET cancel_at_period_end = false,
                               -- The date goes with the flag. The database
                               -- refuses to hold one without the other, and
                               -- a stale effective date on a resumed
                               -- subscription is a promise to end it.
                               cancel_effective_at = NULL,
                               cancelled_at = NULL,
                               updated_at = now()
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

    public function dueForRenewalNotice(int $limit): array
    {
        // The recipient is resolved here, in the same query, rather than by
        // asking the membership repository. That port is deliberately shaped
        // "every tenant this *user* belongs to" and has no "who is in tenant
        // X", because that shape invites a client-supplied tenant id being
        // passed straight through. Nothing here comes from a client — the
        // tenant is read off the subscription row — so the answer belongs in
        // the SQL rather than in a hole cut through that stance.
        //
        // LEFT JOIN, not JOIN: a tenant with no administrator produces a row
        // with no recipient. Dropping it would turn an unmet legal obligation
        // into an empty result, which is the failure R11 actually warns about.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.id, s.tenant_id, s.product_id, s.term_ends_at,
                       s.notice_days, r.user_id AS recipient_user_id
                  FROM subscriptions s
                  LEFT JOIN LATERAL (
                        SELECT s.subscriber_user_id AS user_id
                         WHERE s.subscriber_kind = 'USER'
                           AND s.subscriber_user_id IS NOT NULL
                        UNION
                        SELECT tmr.user_id
                          FROM tenant_member_roles tmr
                          JOIN roles ro ON ro.id = tmr.role_id
                         WHERE s.subscriber_kind = 'TENANT'
                           AND tmr.tenant_id = s.tenant_id
                           AND tmr.product_id = s.product_id
                           AND ro.code = 'TENANT_ADMIN'
                       ) r ON TRUE
                 WHERE s.status = 'ACTIVE'
                   AND s.renewal = 'AUTO_RENEW'
                   AND s.term_ends_at IS NOT NULL
                   AND s.notice_days > 0
                   AND s.cancel_at_period_end = false
                   AND now() >= s.term_ends_at - make_interval(days => s.notice_days)
                   AND now() <  s.term_ends_at
                 ORDER BY s.term_ends_at, r.user_id
                 LIMIT :limit
                SQL,
            ['limit' => $limit],
        );

        return array_map(
            static fn (array $row): RenewalNotice => new RenewalNotice(
                Row::string($row, 'id'),
                Row::string($row, 'tenant_id'),
                Row::string($row, 'product_id'),
                Row::timestamp($row, 'term_ends_at'),
                Row::integer($row, 'notice_days'),
                Row::nullableString($row, 'recipient_user_id'),
            ),
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

    /**
     * One subscription by id, whatever its status or subscriber.
     *
     * The seat lookups need this: a seat is not reachable by (tenant,
     * product), which is the tenant's own subscription.
     */
    public function findById(string $subscriptionId): ?Subscription
    {
        if (!Uuid::isValid($subscriptionId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE s.id = :id',
            ['id' => $subscriptionId],
        );

        return $row === false ? null : $this->toSubscription($row);
    }

    /**
     * Every live subscription that entitles this person: the tenant's own,
     * plus their seat if they hold one.
     *
     * @return list<Subscription>
     */
    public function liveFor(string $tenantId, string $productId, string $userId): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($userId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . <<<'SQL'
                 WHERE s.tenant_id = :tenantId
                   AND s.product_id = :productId
                   AND s.status = 'ACTIVE'
                   AND (s.subscriber_kind = 'TENANT' OR s.subscriber_user_id = :userId)
                 ORDER BY s.subscriber_kind, s.started_at DESC
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        return array_map($this->toSubscription(...), $rows);
    }

    public function scheduleCancellation(
        Subscription $subscription,
        CancellationDecision $decision,
        ?string $actorUserId,
        ?callable $alsoCharge = null,
    ): Subscription {
        return $this->connection->transactional(
            function () use ($subscription, $decision, $actorUserId, $alsoCharge): Subscription {
                if ($decision->effect === CancellationDecision::IMMEDIATE) {
                    // Ends now. The entitlement goes with it, because the
                    // clock is what entitlement resolution asks and there is
                    // no period left to be inside.
                    $this->connection->executeStatement(
                        <<<'SQL'
                        UPDATE subscriptions
                           SET status = 'CANCELLED',
                               cancel_at_period_end = false,
                               cancel_effective_at = NULL,
                               cancelled_at = coalesce(cancelled_at, now()),
                               ended_at = coalesce(ended_at, now()),
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                        ['id' => $subscription->id],
                    );

                    // The entitlements go with it. There is no period left to
                    // be inside, and a cancelled subscription still granting
                    // capabilities is access nobody is paying for.
                    $this->endEntitlements($subscription->id);
                } else {
                    // Still live, and still owed, until the date the customer
                    // was given. Storing that date is what makes the promise
                    // checkable later.
                    $this->connection->executeStatement(
                        <<<'SQL'
                        UPDATE subscriptions
                           SET cancel_at_period_end = true,
                               cancel_effective_at = :effectiveAt,
                               cancelled_at = coalesce(cancelled_at, now()),
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                        [
                            'id' => $subscription->id,
                            'effectiveAt' => self::moment($decision->effectiveAt),
                        ],
                    );
                }

                $this->record(
                    $subscription->id,
                    $decision->effect === CancellationDecision::IMMEDIATE
                        ? SubscriptionEvent::CANCELLED
                        : SubscriptionEvent::CANCELLATION_SCHEDULED,
                    null,
                    $subscription->offer->version->id,
                    $actorUserId,
                    // The decision travels with the event: which rule decided,
                    // when it takes effect, and what the customer was told.
                    $decision->toArray(),
                );

                $updated = $this->findById($subscription->id);

                if ($updated === null) {
                    throw new RuntimeException('The subscription vanished while being cancelled.');
                }

                // On this transaction, never one of its own: what the exit
                // costs is part of the exit. It runs last so the charge
                // describes the subscription as it now stands, and it throws
                // rather than returns on failure — a rollback here takes the
                // release with it, which is the point.
                if ($alsoCharge !== null) {
                    $alsoCharge($updated);
                }

                return $updated;
            },
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
            new SubscriptionTerms(
                Row::nullableInteger($row, 'version_term_months'),
                Row::integer($row, 'version_commitment_months'),
                Row::string($row, 'version_cancellation_policy'),
                Row::string($row, 'version_renewal'),
                Row::string($row, 'version_early_termination'),
                Row::integer($row, 'version_notice_days'),
            ),
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
            Subscriber::of(
                Row::string($row, 'subscriber_kind'),
                Row::nullableString($row, 'subscriber_user_id'),
            ),
            new SubscriptionTerms(
                Row::nullableInteger($row, 'term_months'),
                Row::integer($row, 'commitment_months'),
                Row::string($row, 'cancellation_policy'),
                Row::string($row, 'renewal'),
                Row::string($row, 'early_termination'),
                Row::integer($row, 'notice_days'),
            ),
            Row::string($row, 'status'),
            Row::timestamp($row, 'started_at'),
            Row::timestamp($row, 'current_period_start'),
            Row::nullableTimestamp($row, 'current_period_end'),
            self::boolean($row, 'cancel_at_period_end'),
            Row::nullableTimestamp($row, 'cancelled_at'),
            Row::nullableTimestamp($row, 'ended_at'),
            Row::nullableTimestamp($row, 'term_ends_at'),
            Row::nullableTimestamp($row, 'commitment_ends_at'),
            Row::nullableTimestamp($row, 'cancel_effective_at'),
            Row::nullableString($row, 'owner_user_id'),
        );
    }

    public function membersOf(string $subscriptionId): array
    {
        if (!Uuid::isValid($subscriptionId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT m.user_id, u.email, u.display_name, m.added_at
                  FROM subscription_members m
                  JOIN users u ON u.id = m.user_id
                 WHERE m.subscription_id = :id
                 ORDER BY m.added_at, u.email
                SQL,
            ['id' => $subscriptionId],
        );

        return array_map(static fn (array $row): SubscriptionMember => new SubscriptionMember(
            Row::string($row, 'user_id'),
            Row::nullableString($row, 'email'),
            Row::nullableString($row, 'display_name'),
            Row::timestamp($row, 'added_at'),
        ), $rows);
    }

    public function addMember(string $subscriptionId, string $userId, ?string $addedBy): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO subscription_members (subscription_id, user_id, added_by)
                VALUES (:id, :user, :by)
                ON CONFLICT DO NOTHING
                SQL,
            ['id' => $subscriptionId, 'user' => $userId, 'by' => $addedBy],
        );
    }

    public function removeMember(string $subscriptionId, string $userId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM subscription_members WHERE subscription_id = :id AND user_id = :user',
            ['id' => $subscriptionId, 'user' => $userId],
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
