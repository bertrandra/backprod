<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

/**
 * What a tenant may use for a product.
 *
 * Two methods with deliberately different costs. capabilitiesFor() runs
 * inside the §10.6 chain on every authenticated request and answers the only
 * question that hot path asks — which codes are live. entitlementsFor()
 * returns the limits and provenance beside them, and is called when someone
 * actually needs them: a quota check, or a page showing what the tenant has.
 *
 * Both are answered against the clock. An entitlement carries the window it
 * was granted for, so access ends when the subscription period does, whether
 * or not anything has swept the rows.
 *
 * And both are answered for a *person* when one is named. A seat (§13.1) is
 * a subscription addressed to an individual, so what it grants belongs to
 * them alone; naming nobody asks the tenant-wide question, and that answer
 * contains no seat at all. Getting this wrong makes seats decorative — one
 * person buys one and the whole tenant is entitled.
 */
interface EntitlementRepository
{
    /**
     * The capabilities a tenant may currently use for a product.
     *
     * Capabilities, not plan names (§13): the caller of RequestContext::allows()
     * must never need to know which offer produced them.
     *
     * @param string|null $userId whose seats count; null asks the tenant-wide
     *                            question, which no seat answers
     *
     * @return list<string>
     */
    public function capabilitiesFor(string $tenantId, string $productId, ?string $userId = null): array;

    /**
     * The same set, with limits and provenance.
     *
     * @param string|null $userId whose seats count; null asks the tenant-wide
     *                            question, which no seat answers
     *
     * @return list<Entitlement>
     */
    public function entitlementsFor(string $tenantId, string $productId, ?string $userId = null): array;

    /**
     * Whether this person is one of the people a subscription covers
     * (2026-09-25).
     *
     * **A different question from "what may they use".** The two above
     * answer which *features* are live; this answers whether the person is
     * on a subscription at all — its owner, or somebody the owner added
     * within the number of people their offer sells.
     *
     * It has to be asked separately, because the obvious shortcuts are both
     * wrong. "Do they hold some capability" lets a tenant-wide override
     * cover a whole organisation, which is how the hole this closes was
     * opened. "Do they hold the workspace quota" refuses a read-only
     * seat — Plan's *Lecture* sells no `max_projects`, because a reader
     * stores nothing to count, and a reader is exactly somebody who should
     * see the work.
     *
     * An override grants features and covers nobody: staff hand out a
     * feature, never a seat.
     */
    public function covers(string $tenantId, string $productId, string $userId): bool;
}
