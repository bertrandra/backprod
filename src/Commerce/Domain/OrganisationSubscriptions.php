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
     * A page of this organisation's subscriptions on this product, live or
     * not — **the living first**, then newest first.
     *
     * Not only the live ones. "Who is subscribed to what" is a present-tense
     * question, but since the tenant surface sells seats only (ADR-055) the
     * organisation's own `/subscription` history is empty for good — so this
     * is the only place a past seat can be seen at all.
     *
     * **Ordered in SQL, and that is why it can be paged** (2026-09-26). This
     * answered with everything and let the screen sort the living to the top,
     * which was fine for a demonstration and wrong for a customer: every
     * cancelled seat stays for ever, so an organisation of two hundred people
     * renewing yearly would eventually be sent thousands of rows in one
     * response. Sorting locally is also what makes paging impossible — page
     * two's live rows would render below page one's dead ones.
     *
     * @return list<HeldSubscription>
     */
    public function of(string $tenantId, string $productId, int $limit, int $offset): array;

    /** How many there are in total, for the page the caller is on. */
    public function countOf(string $tenantId, string $productId): int;
}
