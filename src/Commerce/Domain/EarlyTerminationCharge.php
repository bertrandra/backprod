<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * What it costs to leave a commitment early, raised as a real document
 * (§13.1, §25).
 *
 * A port rather than a call into billing, for the reason every port in this
 * codebase exists: the module that owns the subscription must not know how an
 * invoice is made. Commerce declares what it needs — "charge this exit" — and
 * something in the sales layer decides that this means an invoice, priced
 * from the version the customer actually holds.
 *
 * It also keeps the dependency pointing one way. Billing already reads
 * subscriptions; if subscriptions called billing back, the two would be one
 * module wearing two names.
 */
interface EarlyTerminationCharge
{
    /**
     * Raises the charge, inside the transaction that is cancelling.
     *
     * Participating, never transactional. The cancellation and its charge are
     * one fact: a subscription released with no charge is revenue given away,
     * and a charge with no cancellation is a customer billed for an exit they
     * did not get. Neither may be observable, not even after a crash between
     * them — so this runs on the caller's open transaction and opens none of
     * its own.
     *
     * @return string the id of the document raised
     */
    public function applyCharge(
        Subscription $subscription,
        CancellationDecision $decision,
        ?string $actorUserId,
    ): string;
}
