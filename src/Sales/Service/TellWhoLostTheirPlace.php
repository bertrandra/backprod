<?php

declare(strict_types=1);

namespace App\Sales\Service;

use App\Commerce\Domain\PlacesWithdrawn;
use App\Commerce\Domain\Subscription;
use App\Commerce\Domain\SubscriptionMember;
use App\Notification\Domain\Category;
use App\Notification\Service\Notifications;

/**
 * Tells the holder who their change of plan stopped covering, and tells each
 * of those people themselves (ADR-077).
 *
 * Two notices, because two different people need two different sentences: the
 * holder made a decision and should see what it did, by name; a colleague did
 * nothing and should learn why the workshop now refuses them from a notice
 * rather than from the refusal.
 *
 * Raised on the transaction that moved the plan — {@see Notifications::raise()}
 * participates — so the places going and the intent to say so commit together,
 * and sent through the queue like every other notice (§27.1).
 *
 * `BILLING`, the category the rest of a subscription's life is told in, and
 * not `SECURITY`: nothing was compromised, and a notice nobody may mute is
 * kept for that. An absent preference means enabled, so somebody who never
 * opened the settings still hears it.
 *
 * Once per person per change by the dedup index, never by a check: the key
 * names the subscription and the offer version it moved to.
 */
final class TellWhoLostTheirPlace implements PlacesWithdrawn
{
    public const TO_THE_HOLDER = 'subscription.places_withdrawn';
    public const TO_THE_PERSON = 'subscription.place_withdrawn';

    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function tell(Subscription $subscription, array $withdrawn, int $placesSold): void
    {
        $version = $subscription->offer->version->id;
        $holder = $subscription->ownerUserId ?? $subscription->subscriberUserId;

        $this->notifications->raise(
            $subscription->tenantId,
            $subscription->productId,
            $holder,
            self::TO_THE_HOLDER,
            Category::BILLING,
            [
                'offer' => $subscription->offer->name,
                'places' => $placesSold,
                'count' => count($withdrawn),
                'people' => implode(', ', array_map(self::nameOf(...), $withdrawn)),
                'subscription_id' => $subscription->id,
            ],
            sprintf('places-withdrawn:%s:%s:%s', $subscription->id, $version, $holder),
        );

        foreach ($withdrawn as $person) {
            $this->notifications->raise(
                $subscription->tenantId,
                $subscription->productId,
                $person->userId,
                self::TO_THE_PERSON,
                Category::BILLING,
                [
                    'offer' => $subscription->offer->name,
                    'subscription_id' => $subscription->id,
                ],
                sprintf('place-withdrawn:%s:%s:%s', $subscription->id, $version, $person->userId),
            );
        }
    }

    private static function nameOf(SubscriptionMember $person): string
    {
        return $person->displayName ?? $person->email ?? $person->userId;
    }
}
