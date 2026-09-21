<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

/**
 * What a product can be told (ADR-051 §5): the first set, and the mapping
 * from the platform's own subscription history onto it.
 *
 * Names are the product developer's vocabulary — `subscription.ended`, not
 * `EXPIRED` or `CANCELLED` — because from the product's side both mean one
 * thing: the service is no longer owed. What the product wants to know
 * richer than the name, it fetches through relationship 2.
 */
final class ProductEventType
{
    public const SUBSCRIPTION_STARTED = 'subscription.started';
    public const SUBSCRIPTION_CHANGED = 'subscription.changed';
    public const SUBSCRIPTION_CANCELLATION_SCHEDULED = 'subscription.cancellation_scheduled';
    public const SUBSCRIPTION_ENDED = 'subscription.ended';
    public const MEMBER_ADDED = 'member.added';
    public const MEMBER_REMOVED = 'member.removed';
    public const TENANT_PRODUCT_ASSIGNED = 'tenant.product.assigned';
    public const TENANT_PRODUCT_UNASSIGNED = 'tenant.product.unassigned';
    public const USER_ERASED = 'user.erased';

    /**
     * A subscription event's type (`SubscriptionEvent::*`) as the product
     * hears it, or null for one the product has no use for.
     */
    public static function ofSubscriptionEvent(string $type): ?string
    {
        return match ($type) {
            'ACTIVATED' => self::SUBSCRIPTION_STARTED,
            'OFFER_CHANGED', 'RESUMED', 'RENEWED' => self::SUBSCRIPTION_CHANGED,
            'CANCELLATION_SCHEDULED' => self::SUBSCRIPTION_CANCELLATION_SCHEDULED,
            'CANCELLED', 'EXPIRED' => self::SUBSCRIPTION_ENDED,
            default => null,
        };
    }
}
