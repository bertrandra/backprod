<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\DueRenewal;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferGrant;
use App\Commerce\Domain\OfferVersion;
use App\Commerce\Domain\OverdueSubscription;
use App\Commerce\Domain\PendingChange;
use App\Commerce\Domain\Plan;
use App\Commerce\Domain\RenewalNotice;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Domain\SubscriptionMember;
use App\Commerce\Domain\SubscriptionPlaces;
use App\Commerce\Domain\SubscriptionRepository;
use App\Commerce\Domain\SubscriptionTerms;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
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
final class PostgresSubscriptionRepository implements SubscriptionRepository, SubscriptionPlaces
{
    private const COLUMNS = <<<'SQL'
        s.id, s.tenant_id, s.product_id, s.offer_version_id, s.status,
        s.started_at, s.current_period_start, s.current_period_end,
        s.cancel_at_period_end, s.cancelled_at, s.ended_at,
        s.subscriber_user_id,
        s.term_months, s.term_ends_at, s.commitment_months, s.commitment_ends_at,
        s.cancellation_policy, s.renewal, s.early_termination, s.notice_days,
        s.cancel_effective_at, s.owner_user_id, s.is_freemium,
        s.past_due_since, s.past_due_invoice_id,
        s.pending_offer_version_id, s.pending_effective_at,
        s.pending_requested_at, s.pending_requested_by,
        o.id AS offer_id, o.code AS offer_code, o.name AS offer_name,
        pl.id AS plan_id, pl.code AS plan_code, pl.name AS plan_name, pl.rank AS plan_rank,
        v.version, v.status AS version_status, v.billing_period,
        v.price_minor_units, v.currency, v.valid_from, v.valid_until,
        v.term_months AS version_term_months, v.commitment_months AS version_commitment_months,
        v.cancellation_policy AS version_cancellation_policy, v.renewal AS version_renewal,
        v.early_termination AS version_early_termination, v.notice_days AS version_notice_days,
        po.id AS pending_offer_id, po.code AS pending_offer_code, po.name AS pending_offer_name,
        ppl.id AS pending_plan_id, ppl.code AS pending_plan_code,
        ppl.name AS pending_plan_name, ppl.rank AS pending_plan_rank
        SQL;

    /**
     * The pending offer joins on the **left**: a subscription with no change
     * waiting is the ordinary case, and an inner join would return none of
     * them. The version it points at cannot have gone — the foreign key is
     * RESTRICT — so the three pending columns are present together or absent
     * together, which is what the CHECK enforces and what hydration reads.
     */
    private const FROM = <<<'SQL'
        FROM subscriptions s
        JOIN offer_versions v ON v.id = s.offer_version_id
        JOIN offers o ON o.id = v.offer_id
        JOIN plans pl ON pl.id = o.plan_id
        LEFT JOIN offer_versions pv ON pv.id = s.pending_offer_version_id
        LEFT JOIN offers po ON po.id = pv.offer_id
        LEFT JOIN plans ppl ON ppl.id = po.plan_id
        SQL;

    public function __construct(private readonly Connection $connection)
    {
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
        string $subscriberUserId,
    ): Subscription {
        try {
            return $this->connection->transactional(
                fn (): Subscription => $this->applyActivate(
                    $tenantId,
                    $productId,
                    $offer,
                    $periodEnd,
                    $actorUserId,
                    $subscriberUserId,
                ),
            );
        } catch (UniqueConstraintViolationException $violation) {
            // Caught **outside** the transaction, because inside it the
            // transaction is already aborted and nothing further can be read.
            //
            // Only the freemium index is answered in words. The active-scope
            // indexes are refused a step earlier, in the service, with a
            // message a client can act on; reaching one here means two
            // simultaneous requests raced, and the honest answer is the
            // collision rather than a sentence invented about which of them
            // was second. Every other unique index is a fault and propagates
            // — the mistake `PostgresPaymentRepository` writes down, where
            // answering "already done" to an unrelated constraint lost a
            // payment nobody was told about.
            if (!str_contains($violation->getMessage(), 'subscriptions_one_freemium_ever')) {
                throw $violation;
            }

            // Whatever its status, and that is the rule (§6.4): a freemium
            // that expired six months ago is still a freemium this account
            // has had. The index carries no status filter, so this refusal
            // says the same thing the database says.
            throw new ConflictException(
                'FREEMIUM_ALREADY_USED',
                'This account has already had the free period for this product.',
                ['product_id' => $productId],
            );
        }
    }

    public function applyActivate(
        string $tenantId,
        string $productId,
        SubscribedOffer $offer,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
        string $subscriberUserId,
    ): Subscription {
        // The terms are copied from the version as values, not referenced.
        // Repricing or re-terming the offer tomorrow must not change one
        // condition this subscriber agreed to today — the invoice snapshot
        // rule (§25) applied to the contract (§13.1).
        $terms = $offer->version->terms;
        $startedAt = new DateTimeImmutable();

        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO subscriptions
                    (tenant_id, product_id, offer_version_id, current_period_end,
                     subscriber_user_id, owner_user_id,
                     term_months, term_ends_at, commitment_months, commitment_ends_at,
                     cancellation_policy, renewal, early_termination, notice_days,
                     is_freemium)
                VALUES (:tenantId, :productId, :versionId, :periodEnd,
                        :subscriberUserId, :ownerUserId,
                        :termMonths, :termEndsAt, :commitmentMonths, :commitmentEndsAt,
                        :cancellationPolicy, :renewal, :earlyTermination, :noticeDays,
                        :freemium)
                RETURNING id
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'versionId' => $offer->version->id,
                'periodEnd' => self::moment($periodEnd),
                'subscriberUserId' => $subscriberUserId,
                // The owner (2026-09-19): the person the seat is for.
                // It read "else whoever activated it" while an organisation
                // could subscribe; with one kind left there is no else, and
                // `$actorUserId` keeps its other job — the trail.
                'ownerUserId' => $subscriberUserId,
                'termMonths' => $terms->termMonths,
                'termEndsAt' => self::moment($terms->termEndsFrom($startedAt)),
                'commitmentMonths' => $terms->commitmentMonths,
                'commitmentEndsAt' => self::moment($terms->commitmentEndsFrom($startedAt)),
                'cancellationPolicy' => $terms->cancellationPolicy,
                'renewal' => $terms->renewal,
                'earlyTermination' => $terms->earlyTermination,
                'noticeDays' => $terms->noticeDays,
                // Snapshotted from the version, exactly like the terms above
                // and for the same reason: what is being recorded is what was
                // sold. Derived from the offer's own properties — free, and
                // over when its period is — so nothing here names a plan, and
                // no caller gets to decide that a priced offer was free.
                'freemium' => $offer->version->isFreemium(),
            ],
            // The one typed parameter here, and it has to be: PostgreSQL is
            // handed `''` for a PHP `false` otherwise, and refuses it as a
            // boolean. Every other value in this statement is a string, an
            // integer or null, which need no help.
            ['freemium' => ParameterType::BOOLEAN],
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
        DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        array $detail = [],
        ?callable $alsoBill = null,
    ): Subscription {
        return $this->connection->transactional(
            function () use (
                $subscription,
                $offer,
                $direction,
                $actorUserId,
                $periodStart,
                $periodEnd,
                $detail,
                $alsoBill,
            ): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET offer_version_id = :versionId,
                               term_months = :termMonths,
                               term_ends_at = :termEndsAt,
                               commitment_months = :commitmentMonths,
                               commitment_ends_at = :commitmentEndsAt,
                               cancellation_policy = :cancellationPolicy,
                               renewal = :renewal,
                               early_termination = :earlyTermination,
                               notice_days = :noticeDays,
                               current_period_start = :periodStart,
                               current_period_end = :periodEnd,
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                    self::reSnapshot($subscription, $offer, $periodStart)
                        + [
                            // The anchor resets (2026-09-27, spec §3): the
                            // period the customer has just been credited for is
                            // over. The two dates are the caller's, from one
                            // reading of the clock — the same instant the credit
                            // was computed against and the invoice is dated.
                            'periodStart' => self::moment($periodStart),
                            'periodEnd' => self::moment($periodEnd),
                            'id' => $subscription->id,
                        ],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::OFFER_CHANGED,
                    $subscription->offer->version->id,
                    $offer->version->id,
                    $actorUserId,
                    ['direction' => $direction] + $detail,
                );

                // The old grants go, the new ones arrive, and they run to the
                // new period's end: what the tenant may use changes, and so
                // does what they have paid it for until.
                $this->revokeEntitlements($subscription->id);
                $this->grantEntitlements(
                    $subscription->id,
                    $subscription->tenantId,
                    $subscription->productId,
                    $offer,
                    $periodEnd,
                );

                $moved = $this->requireById($subscription->id, 'changed');

                // Last, and on this transaction: the invoice names the
                // subscription as it now stands, and the move and the document
                // commit together or neither does.
                if ($alsoBill !== null) {
                    $alsoBill($moved);
                }

                return $moved;
            },
        );
    }

    /**
     * The terms a subscription carries once it has moved to another offer
     * version (spec §1c, §3.2) — as values, exactly as `applyActivate` copies
     * them when the subscription is first taken out.
     *
     * Until 2026-09-27 a change of offer wrote `offer_version_id` and nothing
     * else, so the subscription pointed at the new version while still
     * carrying the **conditions of the offer it had left**: the old
     * commitment bound the new plan, the old cancellation policy decided how
     * to leave it, and the old notice applied. A snapshot that is not
     * retaken is not a snapshot of anything.
     *
     * The term is counted from the moment of the change, not from
     * `started_at`: the new version sells *its* number of months and the
     * customer is buying them now. Counting from the start would hand
     * somebody a twelve-month term with seven months already spent.
     *
     * **`commitment_months` and `commitment_ends_at` are the exception**, and
     * the reasoning is on {@see Subscription::commitmentAfterMovingTo()}
     * because that is where the decision is made: a change of plan is not a
     * new contract, so the commitment neither re-arms nor shortens, and the
     * arriving offer's applies only if it ends later.
     *
     * @return array<string, scalar|null>
     */
    private static function reSnapshot(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $at,
    ): array {
        $terms = $offer->version->terms;
        $commitment = $subscription->commitmentAfterMovingTo($terms, $at);

        return [
            'versionId' => $offer->version->id,
            'termMonths' => $terms->termMonths,
            'termEndsAt' => self::moment($terms->termEndsFrom($at)),
            'commitmentMonths' => $commitment->months,
            'commitmentEndsAt' => self::moment($commitment->endsAt),
            'cancellationPolicy' => $terms->cancellationPolicy,
            'renewal' => $terms->renewal,
            'earlyTermination' => $terms->earlyTermination,
            'noticeDays' => $terms->noticeDays,
        ];
    }

    public function scheduleChange(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $effectiveAt,
        ?string $actorUserId,
    ): Subscription {
        return $this->connection->transactional(
            function () use ($subscription, $offer, $effectiveAt, $actorUserId): Subscription {
                // Four columns and nothing else. **No entitlement moves**,
                // and that is the whole point of the deferral: the customer
                // keeps the plan they paid for, entire, until the date they
                // paid it to.
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET pending_offer_version_id = :versionId,
                               pending_effective_at = :effectiveAt,
                               pending_requested_at = now(),
                               pending_requested_by = :actor,
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                    [
                        'versionId' => $offer->version->id,
                        'effectiveAt' => self::moment($effectiveAt),
                        'actor' => $actorUserId,
                        'id' => $subscription->id,
                    ],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::CHANGE_SCHEDULED,
                    $subscription->offer->version->id,
                    $offer->version->id,
                    $actorUserId,
                    ['effective_at' => $effectiveAt->format(DATE_ATOM)],
                );

                return $this->requireById($subscription->id, 'scheduled');
            },
        );
    }

    public function cancelScheduledChange(Subscription $subscription, ?string $actorUserId): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $actorUserId): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET pending_offer_version_id = NULL,
                               pending_effective_at = NULL,
                               pending_requested_at = NULL,
                               pending_requested_by = NULL,
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                    ['id' => $subscription->id],
                );

                // The withdrawn destination travels on the event, because
                // the columns that held it are now NULL and "what did I
                // cancel?" has to stay answerable.
                $this->record(
                    $subscription->id,
                    SubscriptionEvent::CHANGE_CANCELLED,
                    $subscription->pending?->offerVersionId,
                    $subscription->offer->version->id,
                    $actorUserId,
                    [],
                );

                return $this->requireById($subscription->id, 'unscheduled');
            },
        );
    }

    /**
     * Applies a change that has come due (spec §4.3), at renewal.
     *
     * Everything a change of offer does, plus the period: the arriving
     * version's grants replace the leaving one's, the terms are
     * re-snapshotted from it, and the period is reset from the end of the
     * one that has just finished — not from `now()`, for the reason
     * {@see self::renew()} gives, which is that applying it an hour late
     * must not cost the customer an hour.
     *
     * The arriving version is read **by id**, not through the catalogue: it
     * may have been withdrawn from sale between the request and the date,
     * and the customer was promised that plan regardless. A withdrawn offer
     * still entitles the people already on it (that is the rule the
     * catalogue's own gate is written around), and this is the same rule one
     * moment earlier.
     *
     * One event, `OFFER_CHANGED`, because one thing happened: the
     * subscription moved to the offer it was going to move to. The detail
     * says it arrived by schedule rather than by somebody clicking.
     */
    public function applyPendingChange(Subscription $subscription, string $direction): Subscription
    {
        $pending = $subscription->pending;

        if ($pending === null) {
            throw new RuntimeException('There is no pending change to apply.');
        }

        $offer = $this->offerOfVersion($pending->offerVersionId);
        $from = $subscription->currentPeriodEnd ?? new DateTimeImmutable();
        $periodEnd = $offer->version->periodEndFrom($from);

        return $this->connection->transactional(
            function () use ($subscription, $offer, $periodEnd, $pending, $direction): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET offer_version_id = :versionId,
                               term_months = :termMonths,
                               term_ends_at = :termEndsAt,
                               commitment_months = :commitmentMonths,
                               commitment_ends_at = :commitmentEndsAt,
                               cancellation_policy = :cancellationPolicy,
                               renewal = :renewal,
                               early_termination = :earlyTermination,
                               notice_days = :noticeDays,
                               current_period_start = coalesce(current_period_end, now()),
                               current_period_end = :periodEnd,
                               -- Only ever set, never cleared (ADR-059). A free
                               -- period reached by a scheduled change spends the
                               -- right exactly as the door does, and the move up
                               -- out of it later does not hand the right back.
                               is_freemium = is_freemium OR :arrivingIsFree,
                               pending_offer_version_id = NULL,
                               pending_effective_at = NULL,
                               pending_requested_at = NULL,
                               pending_requested_by = NULL,
                               updated_at = now()
                         WHERE id = :id
                        SQL,
                    self::reSnapshot($subscription, $offer, new DateTimeImmutable())
                        + [
                            'periodEnd' => self::moment($periodEnd),
                            'arrivingIsFree' => $offer->version->isFreemium(),
                            'id' => $subscription->id,
                        ],
                    // Typed, because PDO hands an untyped `false` to
                    // PostgreSQL as an empty string and the column refuses it.
                    ['arrivingIsFree' => ParameterType::BOOLEAN],
                );

                $this->record(
                    $subscription->id,
                    SubscriptionEvent::OFFER_CHANGED,
                    $subscription->offer->version->id,
                    $offer->version->id,
                    // Nobody did this now: the person who asked is on the
                    // CHANGE_SCHEDULED event, and what applied it is the
                    // clock.
                    null,
                    [
                        'direction' => $direction,
                        'applied' => 'AT_RENEWAL',
                        'requested_at' => $pending->requestedAt->format(DATE_ATOM),
                    ],
                );

                $this->revokeEntitlements($subscription->id);
                $this->grantEntitlements(
                    $subscription->id,
                    $subscription->tenantId,
                    $subscription->productId,
                    $offer,
                    $periodEnd,
                );

                return $this->requireById($subscription->id, 'changed');
            },
        );
    }

    /**
     * One offer version by id, with its grants and the terms it sells.
     *
     * Deliberately not the catalogue's `offerOnSale`: this answers "what was
     * promised", and an offer withdrawn since the promise was made is still
     * what was promised.
     */
    private function offerOfVersion(string $versionId): SubscribedOffer
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT o.id AS offer_id, o.code AS offer_code, o.name AS offer_name,
                       pl.id AS plan_id, pl.code AS plan_code, pl.name AS plan_name, pl.rank AS plan_rank,
                       v.id AS offer_version_id, v.version, v.status AS version_status,
                       v.billing_period, v.price_minor_units, v.currency,
                       v.valid_from, v.valid_until,
                       v.term_months AS version_term_months,
                       v.commitment_months AS version_commitment_months,
                       v.cancellation_policy AS version_cancellation_policy,
                       v.renewal AS version_renewal,
                       v.early_termination AS version_early_termination,
                       v.notice_days AS version_notice_days
                  FROM offer_versions v
                  JOIN offers o ON o.id = v.offer_id
                  JOIN plans pl ON pl.id = o.plan_id
                 WHERE v.id = :id
                SQL,
            ['id' => $versionId],
        );

        if ($row === false) {
            throw new RuntimeException('The offer version a subscription was moving to has vanished.');
        }

        return new SubscribedOffer(
            Row::string($row, 'offer_id'),
            Row::string($row, 'offer_code'),
            Row::string($row, 'offer_name'),
            new Plan(
                Row::string($row, 'plan_id'),
                Row::string($row, 'plan_code'),
                Row::string($row, 'plan_name'),
                Row::integer($row, 'plan_rank'),
            ),
            $this->toVersion($row),
        );
    }

    private function requireById(string $subscriptionId, string $what): Subscription
    {
        $subscription = $this->findById($subscriptionId);

        if ($subscription === null) {
            throw new RuntimeException(sprintf('The subscription vanished while being %s.', $what));
        }

        return $subscription;
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

                return $this->requireActive($subscription);
            },
        );
    }

    public function dueForRenewal(int $limit, int $leadDays): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.id, s.tenant_id, s.product_id, s.subscriber_user_id,
                       s.current_period_end,
                       -- The same expression the overdue read uses, so the two
                       -- cannot come to disagree about what a day is.
                       floor(
                           extract(epoch FROM s.current_period_end - now()) / 86400
                       )::int AS days_until_end
                  FROM subscriptions s
                 WHERE s.status = 'ACTIVE'
                   AND s.current_period_end IS NOT NULL
                   -- Still inside the period, which is what keeps this and
                   -- `expireLapsed()` off the same rows.
                   AND s.current_period_end > now()
                   AND s.current_period_end <= now() + make_interval(days => :lead)
                   -- Nothing the customer has already asked to end.
                   AND s.cancel_at_period_end = false
                   AND (s.cancel_effective_at IS NULL
                        OR s.cancel_effective_at > s.current_period_end)
                   -- A period ending at the term is the last one; what follows
                   -- is tacit renewal, and that is a decision.
                   AND (s.term_ends_at IS NULL OR s.current_period_end < s.term_ends_at)
                 ORDER BY s.current_period_end
                 LIMIT :limit
                SQL,
            ['lead' => $leadDays, 'limit' => $limit],
        );

        return array_map(
            static fn (array $row): DueRenewal => new DueRenewal(
                Row::string($row, 'id'),
                Row::string($row, 'tenant_id'),
                Row::string($row, 'product_id'),
                Row::string($row, 'subscriber_user_id'),
                Row::timestamp($row, 'current_period_end'),
                Row::integer($row, 'days_until_end'),
            ),
            $rows,
        );
    }

    public function renew(
        Subscription $subscription,
        ?DateTimeImmutable $periodEnd,
        callable $alsoBill,
    ): Subscription {
        return $this->connection->transactional(
            function () use ($subscription, $periodEnd, $alsoBill): Subscription {
                $from = $subscription->currentPeriodEnd;

                // The new period starts where the old one ended, not at now():
                // renewing an hour late must not leave the tenant unentitled
                // for that hour, nor silently shorten what they paid for.
                //
                // Conditioned on the period this was asked about, so two
                // overlapping passes cannot both roll it — and, since billing
                // happens below, cannot both invoice it. The statement decides,
                // not a prior read both of them would pass.
                $moved = $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET current_period_start = coalesce(current_period_end, now()),
                               current_period_end = :periodEnd,
                               updated_at = now()
                         WHERE id = :id
                           AND current_period_end IS NOT DISTINCT FROM :from
                        SQL,
                    [
                        'periodEnd' => self::moment($periodEnd),
                        'from' => self::moment($from),
                        'id' => $subscription->id,
                    ],
                );

                if ($moved === 0) {
                    // Somebody else renewed it between the read and here.
                    // Nothing is billed, and the transaction rolls back
                    // whatever this one had started.
                    throw new ConflictException(
                        'RENEWAL_ALREADY_APPLIED',
                        'This period has already been renewed.',
                        ['subscription_id' => $subscription->id],
                    );
                }

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

                // On this transaction, after the move and after the guard: a
                // period extended unbilled is revenue given away, and an
                // invoice for a period the subscription never got is a customer
                // charged for nothing. Neither may be observable.
                $alsoBill($subscription);

                return $this->requireActive($subscription);
            },
        );
    }

    /**
     * Ends a subscription because its period is up and nothing renews it
     * (spec §6.3).
     *
     * The same transition `expireLapsed()` performs in bulk, asked about one
     * subscription the caller is holding — and it writes the same event, for
     * the same reason: a subscription that ended with no trace of ending is
     * the one gap in an otherwise complete history (§18). The detail says the
     * clock arrived at a term that was never going to roll, which is what
     * distinguishes this from the sweep finding a row nobody renewed in time.
     *
     * The entitlements are left alone, deliberately. They carry `valid_until`
     * of the period that has just ended, so they have already lapsed — the
     * clock decides, which is why nothing has to be swept for a refusal to be
     * right.
     */
    public function expire(Subscription $subscription, string $why): Subscription
    {
        return $this->connection->transactional(
            function () use ($subscription, $why): Subscription {
                $this->connection->executeStatement(
                    <<<'SQL'
                        UPDATE subscriptions
                           SET status = 'EXPIRED',
                               ended_at = coalesce(current_period_end, now()),
                               updated_at = now()
                         WHERE id = :id AND status = 'ACTIVE'
                        SQL,
                    ['id' => $subscription->id],
                );

                // No actor: nobody did this, the clock did.
                $this->record($subscription->id, SubscriptionEvent::EXPIRED, $subscription->offer->version->id, null, null, ['reason' => $why]);

                return $this->requireById($subscription->id, 'expired');
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
                        -- The person the subscription is addressed to, and
                        -- nobody else. A second branch fell back to the
                        -- organisation's administrators when the *organisation*
                        -- was the contracting party; that party is gone with
                        -- `subscriber_kind` (2026-10-01), and a tacit renewal
                        -- is notified to whoever agreed to it.
                        SELECT s.subscriber_user_id AS user_id
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

    public function overdue(int $limit): array
    {
        // `coalesce(i.due_at, i.issued_at)`: every invoice this platform raises
        // says "payable on receipt", and `due_at` has never been written by
        // anything — so an invoice with no due date is due when it was issued,
        // which is what its own terms say. Honouring the column where a
        // deployment does set one costs one function call and means the day a
        // payment term exists, this reads it.
        //
        // The recipient is resolved in the same query and for the same reason
        // `dueForRenewalNotice` resolves it here: the membership port is shaped
        // "every tenant this user belongs to" on purpose, and nothing in this
        // query comes from a client.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT s.id, s.tenant_id, s.product_id,
                       i.id AS invoice_id, i.number AS invoice_number,
                       coalesce(i.due_at, i.issued_at) AS due_at,
                       floor(
                           extract(epoch FROM now() - coalesce(i.due_at, i.issued_at)) / 86400
                       )::int AS days_overdue,
                       (s.status = 'PAST_DUE') AS already_in_arrears,
                       r.user_id AS recipient_user_id
                  FROM subscriptions s
                  JOIN invoices i ON i.subscription_id = s.id
                  LEFT JOIN LATERAL (
                        -- Whoever the debt is addressed to: the seat's holder,
                        -- whoever took the subscription out, and — for an
                        -- organisation's — its administrators. The owner is in
                        -- this union and is not in `dueForRenewalNotice`'s,
                        -- because the questions differ: a renewal notice is a
                        -- legal obligation towards the contracting party, and a
                        -- chase is addressed to whoever can settle it, which is
                        -- first of all the person who bought the thing. UNION,
                        -- so an owner who is also an administrator is chased
                        -- once.
                        SELECT s.subscriber_user_id AS user_id
                        UNION
                        SELECT s.owner_user_id
                         WHERE s.owner_user_id IS NOT NULL
                        -- A third branch chased the organisation's
                        -- administrators when the *organisation* owed the
                        -- money. It cannot owe any since ADR-055: a seat is
                        -- the organisation selling to one of its own people,
                        -- so the company is the creditor here and the debtor
                        -- is the person. Chasing the administrators for a
                        -- colleague's own purchase would be telling the wrong
                        -- people about somebody's unpaid bill.
                       ) r ON TRUE
                 WHERE s.status IN ('ACTIVE', 'PAST_DUE')
                   AND i.status = 'ISSUED'
                   AND i.issued_at IS NOT NULL
                   AND coalesce(i.due_at, i.issued_at) < now()
                 ORDER BY coalesce(i.due_at, i.issued_at), i.id, r.user_id
                 LIMIT :limit
                SQL,
            ['limit' => $limit],
        );

        return array_map(
            static fn (array $row): OverdueSubscription => new OverdueSubscription(
                Row::string($row, 'id'),
                Row::string($row, 'tenant_id'),
                Row::string($row, 'product_id'),
                Row::string($row, 'invoice_id'),
                Row::nullableString($row, 'invoice_number'),
                Row::timestamp($row, 'due_at'),
                Row::integer($row, 'days_overdue'),
                self::boolean($row, 'already_in_arrears'),
                Row::nullableString($row, 'recipient_user_id'),
            ),
            $rows,
        );
    }

    public function declareArrears(string $subscriptionId, string $invoiceId): bool
    {
        if (!Uuid::isValid($subscriptionId) || !Uuid::isValid($invoiceId)) {
            return false;
        }

        return $this->connection->transactional(function () use ($subscriptionId, $invoiceId): bool {
            // The status is part of the WHERE, so two overlapping passes cannot
            // both declare it and both write the history row. Whoever loses
            // updates nothing and learns so from the count.
            $changed = $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE subscriptions
                       SET status = 'PAST_DUE',
                           past_due_since = now(),
                           past_due_invoice_id = :invoice,
                           updated_at = now()
                     WHERE id = :id
                       AND status = 'ACTIVE'
                    SQL,
                ['id' => $subscriptionId, 'invoice' => $invoiceId],
            );

            if ($changed === 0) {
                return false;
            }

            // No actor: nobody suspended this, an unpaid invoice did.
            $this->record(
                $subscriptionId,
                SubscriptionEvent::ARREARS_DECLARED,
                null,
                null,
                null,
                ['invoice_id' => $invoiceId],
            );

            return true;
        });
    }

    public function clearArrears(string $invoiceId): bool
    {
        if (!Uuid::isValid($invoiceId)) {
            return false;
        }

        // No transactional() here on purpose, the same choice
        // `Notifications::raise` makes: the caller holds the transaction that
        // marked the invoice paid, and the workshop reopening is part of that
        // one fact. Opening a second transaction inside it would make
        // correctness depend on how the driver nests.
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
                UPDATE subscriptions
                   SET status = 'ACTIVE',
                       past_due_since = NULL,
                       past_due_invoice_id = NULL,
                       updated_at = now()
                 WHERE status = 'PAST_DUE'
                   AND past_due_invoice_id = :invoice
                RETURNING id
                SQL,
            ['invoice' => $invoiceId],
        );

        foreach ($ids as $id) {
            if (is_string($id)) {
                $this->record(
                    $id,
                    SubscriptionEvent::ARREARS_CLEARED,
                    null,
                    null,
                    null,
                    ['invoice_id' => $invoiceId],
                );
            }
        }

        return $ids !== [];
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
            //
            // `PAST_DUE` lapses too (2026-09-27). A period that has run out is
            // over whatever was owed on it, and coverage already reads it as
            // lapsed rather than as arrears — leaving the column at `PAST_DUE`
            // would keep a dead subscription in the collection pass, chasing
            // somebody for a product that has ended. The arrears dates stay on
            // the row: it *was* owed for, and nothing reconstructs that.
            $ids = $this->connection->fetchFirstColumn(
                <<<'SQL'
                    UPDATE subscriptions
                       SET status = 'EXPIRED', ended_at = now(), updated_at = now()
                     WHERE status IN ('ACTIVE', 'PAST_DUE')
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
     * Whether this subscriber's one free period is spent (spec §6.4).
     *
     * Written as the index's own predicate — `coalesce(subscriber_user_id,
     * tenant_id)`, `is_freemium`, and **no status filter** — because the point
     * of this read is to agree with `subscriptions_one_freemium_ever`. Adding
     * `status = 'ACTIVE'` here would offer a button the database refuses,
     * which is the surprise §6.4 asks the catalogue to prevent.
     *
     * It does not enforce anything: the index does, and still has to, because
     * this read and the insert that follows it are two statements.
     */
    public function hasHadFreemium(string $productId, string $subscriberKey): bool
    {
        if (!Uuid::isValid($productId) || !Uuid::isValid($subscriberKey)) {
            return false;
        }

        // A row rather than a boolean expression: PostgreSQL hands `true` back
        // through PDO as `'t'`, and a comparison against `true` would then be
        // false for every account that has had one — the defect this read
        // exists to prevent, inverted. Presence is unambiguous.
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                  FROM subscriptions
                 WHERE product_id = :productId
                   AND coalesce(subscriber_user_id, tenant_id) = :subscriber
                   AND is_freemium
                 LIMIT 1
                SQL,
            ['productId' => $productId, 'subscriber' => $subscriberKey],
        ) !== false;
    }

    /**
     * Every subscription this person holds here: the tenant's own, plus their
     * seat if they have one.
     *
     * `PAST_DUE` is included since 2026-09-27, as in `findActive` above and for
     * the same two reasons: the screen has to show a suspended seat rather than
     * none, and the scope it occupies is not freed by owing for it. What it
     * entitles is `isLiveAt()`, which answers no.
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
                   AND s.status IN ('ACTIVE', 'PAST_DUE')
                   AND s.subscriber_user_id = :userId
                 ORDER BY s.started_at DESC
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        return array_map($this->toSubscription(...), $rows);
    }

    public function coveringPerson(string $tenantId, string $productId, string $userId): ?Subscription
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($userId)) {
            return null;
        }

        // The same three ways in as the entitlement query, composed from the
        // same place: somebody told "you have no subscription" by this read
        // while the workshop lets them in is exactly the disagreement
        // {@see CoversPersonSql} exists to prevent.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM
            . ' WHERE s.tenant_id = :tenantId AND s.product_id = :productId'
            . " AND s.status IN ('ACTIVE', 'PAST_DUE')"
            . ' AND ' . CoversPersonSql::clause('s', ':userId')
            // Theirs first, then the oldest, so the answer does not move
            // between two colleagues' subscriptions from one read to the next.
            //
            // **The first half is not proven.** It needs somebody holding a
            // seat *and* sitting on a colleague's, which nothing in the suite
            // builds and which removing the clause leaves every test green.
            // It is kept because the answer decides whether a screen offers
            // "cancel" or "ask whoever holds it", and reading as a guest while
            // holding your own would be the wrong one — but a reader should
            // know no test defends it.
            . ' ORDER BY (s.owner_user_id = CAST(:userId AS uuid)) DESC NULLS LAST, s.started_at'
            . ' LIMIT 1',
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        $row = $rows[0] ?? null;

        return $row === null ? null : $this->toSubscription($row);
    }

    public function placesUsedBy(string $subscriptionId): int
    {
        if (!Uuid::isValid($subscriptionId)) {
            return 0;
        }

        $used = $this->connection->fetchOne(
            sprintf('SELECT %s FROM subscriptions s WHERE s.id = :id', PlacesUsedSql::count('s')),
            ['id' => $subscriptionId],
        );

        return is_numeric($used) ? (int) $used : 0;
    }

    public function administersSubscription(string $subscriptionId, string $userId): bool
    {
        if (!Uuid::isValid($subscriptionId) || !Uuid::isValid($userId)) {
            return false;
        }

        return $this->connection->fetchOne(
            sprintf(
                'SELECT %s FROM subscriptions s WHERE s.id = :id',
                PlacesUsedSql::administers(':userId', 's'),
            ),
            ['id' => $subscriptionId, 'userId' => $userId],
        ) === true;
    }

    public function holdersCovering(string $tenantId, string $productId, string $userId): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($userId)) {
            return [];
        }

        // Two ways to be covered, in one query rather than two round trips:
        // holding the subscription, or having been added to it. `DISTINCT`
        // because somebody can be both on the same owner's subscription —
        // which `add()` refuses today (`ALREADY_THE_OWNER`) and which a row
        // written before it did could still show.
        //
        // Ordered with the caller's own holding first, so a caller who holds
        // a seat and is also on a colleague's reads as themselves first.
        $rows = $this->connection->fetchFirstColumn(
            // The ordering expression is selected too, because PostgreSQL
            // refuses a SELECT DISTINCT ordered by anything that is not in
            // its list. It changes no row: it is a function of a column
            // already there.
            //
            // The coverage test is {@see CoversPersonSql}'s, since
            // 2026-10-01. It was written here by hand before that, and was
            // already the odd one out: it asked about the owner and the
            // members and not about `subscriber_user_id`, so the three
            // places that decide who a subscription covers did not agree.
            'SELECT DISTINCT s.owner_user_id, (s.owner_user_id = :userId) AS own'
            . ' FROM subscriptions s'
            . ' WHERE s.tenant_id = :tenantId'
            . ' AND s.product_id = :productId'
            . " AND s.status = 'ACTIVE'"
            . ' AND s.owner_user_id IS NOT NULL'
            . ' AND ' . CoversPersonSql::clause('s', ':userId')
            . ' ORDER BY own DESC, s.owner_user_id',
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        $holders = [];

        foreach ($rows as $holder) {
            if (is_string($holder)) {
                $holders[] = $holder;
            }
        }

        return $holders;
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
                               pending_offer_version_id = NULL,
                               pending_effective_at = NULL,
                               pending_requested_at = NULL,
                               pending_requested_by = NULL,
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
                    //
                    // A pending change goes with it, and that is §2.2's
                    // answer to which of two endings wins: a subscription
                    // that is ending has nothing left to become. The
                    // database refuses to hold both, so clearing it here is
                    // what keeps the CHECK unreachable from the application
                    // rather than something a caller has to remember.
                    $this->connection->executeStatement(
                        <<<'SQL'
                        UPDATE subscriptions
                           SET cancel_at_period_end = true,
                               cancel_effective_at = :effectiveAt,
                               cancelled_at = coalesce(cancelled_at, now()),
                               pending_offer_version_id = NULL,
                               pending_effective_at = NULL,
                               pending_requested_at = NULL,
                               pending_requested_by = NULL,
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

    /**
     * The row again, after the change that touched it.
     *
     * **By id since 2026-10-01**, and that is a fix rather than a rename. It
     * read the active subscription of the `(tenant, product)` scope, which was
     * the right row only while a scope held one — so with seats it could have
     * answered with somebody else's, and the method's own message ("the
     * subscription vanished") would never have fired to say so.
     */
    private function requireActive(Subscription $subscription): Subscription
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' ' . self::FROM . ' WHERE s.id = :id',
            ['id' => $subscription->id],
        );

        $row = $rows[0] ?? null;
        $subscription = $row === null ? null : $this->toSubscription($row);

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
        $version = $this->toVersion($row);

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
            Row::string($row, 'subscriber_user_id'),
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
            self::toPendingChange($row),
            self::boolean($row, 'is_freemium'),
            Row::nullableTimestamp($row, 'past_due_since'),
            Row::nullableString($row, 'past_due_invoice_id'),
        );
    }

    /**
     * The offer version a row carries, with its grants and the terms it
     * sells. Shared by the subscription's own version and by the one a
     * pending change is moving to, so the two cannot drift.
     *
     * @param array<string, mixed> $row
     */
    private function toVersion(array $row): OfferVersion
    {
        $versionId = Row::string($row, 'offer_version_id');

        return new OfferVersion(
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
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toPendingChange(array $row): ?PendingChange
    {
        $versionId = Row::nullableString($row, 'pending_offer_version_id');

        if ($versionId === null) {
            return null;
        }

        return new PendingChange(
            $versionId,
            Row::string($row, 'pending_offer_id'),
            Row::string($row, 'pending_offer_code'),
            Row::string($row, 'pending_offer_name'),
            new Plan(
                Row::string($row, 'pending_plan_id'),
                Row::string($row, 'pending_plan_code'),
                Row::string($row, 'pending_plan_name'),
                Row::integer($row, 'pending_plan_rank'),
            ),
            Row::timestamp($row, 'pending_effective_at'),
            Row::timestamp($row, 'pending_requested_at'),
            Row::nullableString($row, 'pending_requested_by'),
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

    public function release(string $tenantId, string $userId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($userId)) {
            return 0;
        }

        // Every subscription of the tenant, live or not. A cancelled one
        // holds no places anybody is counting, and leaving the row behind
        // would keep a departed colleague's name on a list somebody reads.
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM subscription_members m
                 USING subscriptions s
                 WHERE m.subscription_id = s.id
                   AND s.tenant_id = CAST(:tenantId AS uuid)
                   AND m.user_id = CAST(:userId AS uuid)
                SQL,
            ['tenantId' => $tenantId, 'userId' => $userId],
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
