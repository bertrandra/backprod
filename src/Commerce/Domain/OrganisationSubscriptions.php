<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * What an organisation's people hold, read in one go (2026-09-25).
 *
 * A port of its own rather than a method on {@see SubscriptionRepository},
 * because this is a **read model** and that one is the write side: it
 * activates, cancels, renews and carries the invariants. Asking it for a list
 * that joins people and grants would give it a second reason to change.
 *
 * One query, deliberately. The obvious implementation — every subscription,
 * then its members, then its holder — is a query per row, and a list endpoint
 * that scales with the number of seats an organisation bought is one that gets
 * slower the better the customer does.
 */
interface OrganisationSubscriptions
{
    /**
     * Every subscription on this product in this organisation, live or not,
     * newest first.
     *
     * Not only the live ones. "Who is subscribed to what" is a present-tense
     * question, but since the tenant surface sells seats only (ADR-055) the
     * organisation's own `/subscription` history is empty for good — so this
     * is the only place a past seat can be seen at all. Each row carries its
     * status and the screen sorts the living to the top.
     *
     * @return list<HeldSubscription>
     */
    public function of(string $tenantId, string $productId): array;
}
