<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\SubscribedOffer;
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
    ): Subscription {
        if ($this->current($tenantId, $productId) !== null) {
            throw new ConflictException(
                'ALREADY_SUBSCRIBED',
                'This tenant already has a subscription for this product.',
            );
        }

        $offer = $this->sellable($productId, $offerId);

        return $this->subscriptions->activate(
            $tenantId,
            $productId,
            $offer,
            $offer->version->periodEndFrom(new DateTimeImmutable()),
            $actorUserId,
        );
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

    public function cancel(
        string $tenantId,
        string $productId,
        bool $immediately,
        ?string $actorUserId,
    ): Subscription {
        return $this->subscriptions->cancel(
            $this->requireCurrent($tenantId, $productId),
            $immediately,
            $actorUserId,
        );
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

        return $this->subscriptions->renew(
            $subscription,
            $subscription->offer->version->periodEndFrom(
                $subscription->currentPeriodEnd ?? new DateTimeImmutable(),
            ),
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
