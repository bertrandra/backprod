<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Audit\Domain\AuditLog;
use App\Audit\Domain\AuditRecord;
use App\Commerce\Domain\CancellationDecision;
use App\Commerce\Domain\CancellationPolicy;
use App\Commerce\Domain\EarlyTerminationCharge;
use App\Commerce\Domain\SubscribedOffer;
use App\Commerce\Domain\Subscriber;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Commerce\Domain\SubscriptionRepository;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use DateTimeImmutable;

/**
 * The subscription lifecycle for one tenant and product.
 *
 * §37.4 lists what has to work: activation, expiration, renewal, upgrade,
 * downgrade, cancellation and quota exhaustion. All but expiration are
 * deliberate acts and live here; expiration is what the clock does, and is
 * handled by the entitlement window rather than by a method anyone calls.
 */
final class Subscriptions
{
    public const UPGRADE = 'UPGRADE';
    public const DOWNGRADE = 'DOWNGRADE';
    public const LATERAL = 'LATERAL';

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Catalogue $catalogue,
        private readonly CancellationPolicy $policy,
        private readonly EarlyTerminationCharge $charges,
        private readonly AuditLog $audit,
    ) {
    }

    public function current(string $tenantId, string $productId): ?Subscription
    {
        $subscription = $this->subscriptions->findActive($tenantId, $productId);

        // Live means live: a subscription whose period ended is reported as
        // no subscription, not as an active one with a date in the past. The
        // status column may not have caught up, and the caller should not
        // have to know that.
        return $subscription !== null && $subscription->isLiveAt(new DateTimeImmutable())
            ? $subscription
            : null;
    }

    /**
     * @return list<Subscription>
     */
    public function history(string $tenantId, string $productId): array
    {
        return $this->subscriptions->history($tenantId, $productId);
    }

    /**
     * @return list<SubscriptionEvent>
     */
    public function events(string $tenantId, string $productId): array
    {
        $subscription = $this->subscriptions->findActive($tenantId, $productId);

        return $subscription === null ? [] : $this->subscriptions->events($subscription);
    }

    public function subscribe(
        string $tenantId,
        string $productId,
        string $offerId,
        ?string $actorUserId,
        ?Subscriber $subscriber = null,
    ): Subscription {
        $subscriber ??= Subscriber::tenant();

        // A seat and the tenant's own subscription are different scopes, so
        // "already subscribed" is a different question for each. The unique
        // indexes are what actually decide under concurrency; this refusal is
        // the message a client can act on.
        $existing = $subscriber->isSeat()
            ? $this->seatOf($tenantId, $productId, (string) $subscriber->userId)
            : $this->current($tenantId, $productId);

        if ($existing !== null) {
            throw new ConflictException(
                'ALREADY_SUBSCRIBED',
                $subscriber->isSeat()
                    ? 'This person already holds a seat for this product.'
                    : 'This tenant already has a subscription for this product.',
            );
        }

        $offer = $this->sellable($productId, $offerId);

        return $this->subscriptions->activate(
            $tenantId,
            $productId,
            $offer,
            $offer->version->periodEndFrom(new DateTimeImmutable()),
            $actorUserId,
            $subscriber,
        );
    }

    /**
     * The seat a person holds for a product, if any.
     */
    public function seatOf(string $tenantId, string $productId, string $userId): ?Subscription
    {
        foreach ($this->subscriptions->liveFor($tenantId, $productId, $userId) as $subscription) {
            if ($subscription->subscriber->isSeat()) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * Whether this person is entitled to the product right now, by the
     * tenant's subscription or by a seat of their own.
     *
     * Two questions, both of which must be yes: live at this moment, and
     * addressed to them (§13.1).
     */
    public function entitles(string $tenantId, string $productId, string $userId): bool
    {
        $now = new DateTimeImmutable();

        foreach ($this->subscriptions->liveFor($tenantId, $productId, $userId) as $subscription) {
            if ($subscription->entitlesAt($userId, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Moves the tenant onto a different offer.
     *
     * Whether that is an upgrade is decided by comparing plan ranks — two
     * numbers from the database — because §13 forbids the version of this
     * question that reads a plan's name. The answer is recorded on the
     * event, so a later report does not have to recompute it against ranks
     * that may since have moved.
     */
    public function changeOffer(
        string $tenantId,
        string $productId,
        string $offerId,
        ?string $actorUserId,
    ): Subscription {
        $subscription = $this->requireCurrent($tenantId, $productId);
        $offer = $this->sellable($productId, $offerId);

        if ($offer->version->id === $subscription->offer->version->id) {
            throw new ConflictException(
                'ALREADY_ON_OFFER',
                'This tenant is already on those terms.',
            );
        }

        return $this->subscriptions->changeOffer(
            $subscription,
            $offer,
            self::directionBetween($subscription->offer->plan->rank, $offer->plan->rank),
            $actorUserId,
        );
    }

    /**
     * Cancels, or explains why it cannot be cancelled now (§13.1).
     *
     * The decision comes back with the subscription rather than instead of
     * it, because both matter to the caller: what the subscription looks like
     * afterwards, and which rule produced that. A refusal is recorded too —
     * "I cancelled" against "we received nothing" needs an arbiter.
     *
     * When the decision costs something, the invoice for it is raised on the
     * same transaction as the release — see EarlyTerminationCharge — so a
     * customer is never let out unbilled nor billed for an exit they did not
     * get. Its id comes back with the decision, because a charge the caller
     * cannot name is a charge they cannot show anyone.
     *
     * @return array{
     *     subscription: Subscription,
     *     decision: CancellationDecision,
     *     charge_invoice_id: string|null,
     * }
     */
    public function cancel(
        string $tenantId,
        string $productId,
        bool $immediately,
        ?string $actorUserId,
        bool $seat = false,
        ?string $requestId = null,
    ): array {
        $subscription = $this->subscriptionFor($tenantId, $productId, $actorUserId, $seat);

        $decision = $this->policy->decide($subscription, new DateTimeImmutable(), $immediately);

        if (!$decision->accepted) {
            throw new ConflictException(
                'CANCELLATION_NOT_PERMITTED',
                'This subscription cannot be cancelled on these terms.',
                $decision->toArray(),
            );
        }

        /** @var string|null $chargeInvoiceId assigned by reference inside the transaction */
        $chargeInvoiceId = null;

        // Runs on the cancellation's transaction: the release, whatever it
        // costs, and the record of who ended it commit together or not at
        // all. An audit that can be lost while its act succeeds is a log
        // line, and a charge that can be lost is revenue.
        $alsoCharge = function (Subscription $released) use (
            &$chargeInvoiceId,
            $decision,
            $actorUserId,
            $requestId,
            $tenantId,
            $productId,
        ): void {
            // Only a buy-out with something outstanding raises a document. A
            // free early exit and a deferral both cost nothing, and a €0
            // invoice would be a permanent record of no transaction.
            if (($decision->chargeableMonths ?? 0) > 0) {
                $chargeInvoiceId = $this->charges->applyCharge($released, $decision, $actorUserId);
            }

            $this->audit->applyRecord(AuditRecord::of(
                'subscription.cancelled',
                'subscription',
                $released->id,
                $tenantId,
                $productId,
                $actorUserId,
                null,
                $requestId,
                // The decision, not a summary of it: which rule decided, when
                // it takes effect and what it cost are exactly what a dispute
                // about this cancellation will ask for.
                $decision->toArray() + ['charge_invoice_id' => $chargeInvoiceId],
            ));
        };

        $released = $this->subscriptions->scheduleCancellation(
            $subscription,
            $decision,
            $actorUserId,
            $alsoCharge,
        );

        return [
            'subscription' => $released,
            'decision' => $decision,
            'charge_invoice_id' => $chargeInvoiceId,
        ];
    }

    /**
     * What a customer actually wants to know: until when is it paid, until
     * when am I committed, and when may I leave (§13.1).
     *
     * @return array{subscription: Subscription, decision: CancellationDecision}
     */
    public function schedule(
        string $tenantId,
        string $productId,
        ?string $actorUserId = null,
        bool $seat = false,
        ?string $requestId = null,
    ): array {
        $subscription = $this->subscriptionFor($tenantId, $productId, $actorUserId, $seat);

        // The same decision the cancel endpoint would make, with no side
        // effect — the diagnostic and the action cannot disagree because they
        // are one code path, as with the fiscal module's /tax/calculate.
        return [
            'subscription' => $subscription,
            'decision' => $this->policy->decide($subscription, new DateTimeImmutable()),
        ];
    }

    /**
     * Which subscription the caller means: the tenant's, or their own seat.
     *
     * Never an id from the request. The two subscribers §13.1 allows are the
     * tenant — resolved from the context — and the person making the call,
     * also from the context, so there is no id for a client to supply and
     * nothing to check it against. An earlier version took a subscription id
     * and scoped it by tenant; this needs neither the parameter nor the
     * check, which is the better answer to "an id is not an authorisation".
     */
    private function subscriptionFor(
        string $tenantId,
        string $productId,
        ?string $actorUserId,
        bool $seat,
    ): Subscription {
        if (!$seat) {
            return $this->requireCurrent($tenantId, $productId);
        }

        $held = $actorUserId === null
            ? null
            : $this->seatOf($tenantId, $productId, $actorUserId);

        if ($held === null) {
            throw new NotFoundException(
                'You hold no seat for this product.',
                [],
                'NO_SEAT',
            );
        }

        return $held;
    }

    public function resume(string $tenantId, string $productId, ?string $actorUserId): Subscription
    {
        $subscription = $this->requireCurrent($tenantId, $productId);

        if (!$subscription->cancelAtPeriodEnd) {
            throw new ConflictException(
                'NOT_CANCELLING',
                'This subscription is not scheduled to end.',
            );
        }

        return $this->subscriptions->resume($subscription, $actorUserId);
    }

    /**
     * Rolls a subscription into its next period.
     *
     * No endpoint reaches this: renewal is something time does, and the job
     * that notices arrives with M7. It exists now so the behaviour is
     * written and tested rather than waiting on a scheduler — a renewal path
     * first exercised in production is a renewal path nobody has seen work.
     */
    public function renew(string $tenantId, string $productId): Subscription
    {
        $subscription = $this->requireCurrent($tenantId, $productId);
        $from = $subscription->currentPeriodEnd ?? new DateTimeImmutable();

        // A cancellation already due is not something renewal may roll past.
        // Renewing here would extend a subscription beyond the date the
        // customer was given and start billing a period they cancelled —
        // exactly the promise §13.1 says has to stay checkable. A deferral
        // to a commitment further out does not block anything: it is only
        // due once its date arrives.
        if ($subscription->isDueToEndBy($from)) {
            throw new ConflictException(
                'CANCELLATION_DUE',
                'This subscription is due to end and cannot be renewed.',
                ['cancel_effective_at' => $subscription->cancelEffectiveAt?->format(DATE_ATOM)],
            );
        }

        return $this->subscriptions->renew(
            $subscription,
            $subscription->offer->version->periodEndFrom($from),
        );
    }

    /**
     * A change is an upgrade or a downgrade according to rank, and lateral
     * when the ranks match — moving between two offers on the same tier is
     * neither, and calling it one would put a wrong word in the audit trail.
     */
    public static function directionBetween(int $fromRank, int $toRank): string
    {
        if ($toRank > $fromRank) {
            return self::UPGRADE;
        }

        return $toRank < $fromRank ? self::DOWNGRADE : self::LATERAL;
    }

    /**
     * The end of a period that starts at $from.
     *
     * A CUSTOM billing period has no computable end, so it gets none: the
     * subscription runs until someone ends it. Guessing a month for it would
     * silently cut short terms that were negotiated precisely because they
     * do not fit a month.
     */
    private function requireCurrent(string $tenantId, string $productId): Subscription
    {
        $subscription = $this->current($tenantId, $productId);

        if ($subscription === null) {
            throw new NotFoundException(
                'This tenant has no active subscription for this product.',
                [],
                'NO_SUBSCRIPTION',
            );
        }

        return $subscription;
    }

    /**
     * The offer as it may be bought today.
     *
     * Buying goes through the catalogue rather than straight to storage, so
     * a tenant cannot subscribe to something that is not for sale — a draft,
     * or an offer withdrawn last week — by naming its id.
     */
    private function sellable(string $productId, string $offerId): SubscribedOffer
    {
        $offer = $this->catalogue->offerOnSale($productId, $offerId);

        return SubscribedOffer::from($offer);
    }

}
