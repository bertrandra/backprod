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
 */
interface EntitlementRepository
{
    /**
     * The capabilities a tenant may currently use for a product.
     *
     * Capabilities, not plan names (§13): the caller of RequestContext::allows()
     * must never need to know which offer produced them.
     *
     * @return list<string>
     */
    public function capabilitiesFor(string $tenantId, string $productId): array;

    /**
     * The same set, with limits and provenance.
     *
     * @return list<Entitlement>
     */
    public function entitlementsFor(string $tenantId, string $productId): array;
}
