<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Freeing the places somebody occupies on their organisation's subscriptions
 * (2026-09-26).
 *
 * Its own port, narrow on purpose, for the same reason
 * {@see \App\Entitlement\Domain\EntitlementRepository} is narrow: the caller
 * is the tenant module removing a member, and it has no business being able
 * to activate, cancel or re-term a subscription. One method is the whole of
 * what leaving an organisation does to commerce.
 */
interface SubscriptionPlaces
{
    /**
     * Takes somebody off every subscription this organisation holds, and says
     * how many places that freed.
     *
     * Nothing did this until today: `subscription_members` cascades from
     * `users` and from `subscriptions` and from nothing else, so a departed
     * colleague went on occupying a place their organisation had paid for,
     * and the owner met `PEOPLE_QUOTA_REACHED` trying to put somebody in
     * their chair.
     *
     * Across the tenant rather than per product, because a membership is the
     * tenant's and is only mirrored onto its products (ADR-047): somebody who
     * has left has left all of them.
     *
     * **It does not touch a subscription they own.** Whether a seat outlives
     * the person who bought it — cancelled, transferred, left running to the
     * end of the paid period — is a commercial decision, and taking it here
     * silently would be making it.
     *
     * @return int the number of places freed
     */
    public function release(string $tenantId, string $userId): int;
}
