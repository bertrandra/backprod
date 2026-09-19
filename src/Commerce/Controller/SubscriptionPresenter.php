<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionEvent;
use App\Entitlement\Domain\Entitlement;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for a subscription, its history and what it entitles.
 *
 * The offer is returned as the tenant bought it — that exact version, with
 * its price and grants — which is the one place a withdrawn offer stays
 * visible to the people who paid for it.
 */
final class SubscriptionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'offer' => [
                'id' => $subscription->offer->offerId,
                'code' => $subscription->offer->code,
                'name' => $subscription->offer->name,
                'plan' => CataloguePresenter::plan($subscription->offer->plan),
                'version' => CataloguePresenter::version($subscription->offer->version),
            ],
            'started_at' => self::moment($subscription->startedAt),
            // The subscription's period, which §12 keeps distinct from the
            // offer's commercial window above.
            'current_period_start' => self::moment($subscription->currentPeriodStart),
            'current_period_end' => self::nullableMoment($subscription->currentPeriodEnd),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd,
            'cancel_effective_at' => self::nullableMoment($subscription->cancelEffectiveAt),
            'cancelled_at' => self::nullableMoment($subscription->cancelledAt),
            // Who is bound, and by what (§13.1). The terms are the ones
            // snapshotted when this was taken out, not the offer's current
            // ones — which is the whole point of snapshotting them.
            'subscriber' => [
                'kind' => $subscription->subscriber->kind,
                'user_id' => $subscription->subscriber->userId,
            ],
            // Who activated it and manages its people (2026-09-19).
            'owner_user_id' => $subscription->ownerUserId,
            'terms' => [
                'term_months' => $subscription->terms->termMonths,
                'term_ends_at' => self::nullableMoment($subscription->termEndsAt),
                'commitment_months' => $subscription->terms->commitmentMonths,
                'commitment_ends_at' => self::nullableMoment($subscription->commitmentEndsAt),
                'cancellation_policy' => $subscription->terms->cancellationPolicy,
                'renewal' => $subscription->terms->renewal,
                'early_termination' => $subscription->terms->earlyTermination,
                'notice_days' => $subscription->terms->noticeDays,
            ],
            'ended_at' => self::nullableMoment($subscription->endedAt),
        ];
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $subscriptions): array
    {
        return array_map(self::one(...), $subscriptions);
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(SubscriptionEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'from_offer_version_id' => $event->fromOfferVersionId,
            'to_offer_version_id' => $event->toOfferVersionId,
            'actor_user_id' => $event->actorUserId,
            'detail' => $event->detail,
            'occurred_at' => self::moment($event->occurredAt),
        ];
    }

    /**
     * @param list<SubscriptionEvent> $events
     *
     * @return list<array<string, mixed>>
     */
    public static function events(array $events): array
    {
        return array_map(self::event(...), $events);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entitlement(Entitlement $entitlement): array
    {
        return [
            'feature' => $entitlement->featureCode,
            'name' => $entitlement->featureName,
            'kind' => $entitlement->kind,
            'unit' => $entitlement->unit,
            // As in the catalogue: null limit means two different things, so
            // the flag says which.
            'limit' => $entitlement->limit,
            'unlimited' => $entitlement->isUnlimited(),
            'source' => $entitlement->source,
            'valid_until' => self::nullableMoment($entitlement->validUntil),
        ];
    }

    /**
     * @param list<Entitlement> $entitlements
     *
     * @return list<array<string, mixed>>
     */
    public static function entitlements(array $entitlements): array
    {
        return array_map(self::entitlement(...), $entitlements);
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
