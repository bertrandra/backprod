<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * The unconsumed value of a period that is being cut short, and giving it back
 * (spec §3.3).
 *
 * The operator decided on 2026-09-26 that the credit **returns to the payment
 * method** rather than appearing as a negative line on a document: the
 * customer's card sees it, and there is no invoice with a negative line to
 * explain. So this port's other side is a refund — and, since ADR-058, a
 * refund carries its credit note, which is the document that makes the money
 * legal.
 *
 * A port rather than a call into payments, for the reason {@see ChangeCharge}
 * is one: Commerce says what it needs and does not learn how money goes back.
 *
 * **Two methods, and the first one is what makes the second safe.**
 * {@see collectedFor} is read-only and answers what may be given back at all;
 * the decision is bounded by its answer, so the refund is never asked for more
 * than the payment and its invoice can carry. Discovering that at the moment
 * money is requested would be discovering it too late.
 */
interface ChangeCredit
{
    /**
     * What the current period collected, and how much of it may still go back.
     *
     * Never throws for "nothing was collected": a period nobody paid for has
     * no unconsumed value, which is an answer rather than an error — see
     * {@see CollectedPeriod}.
     */
    public function collectedFor(Subscription $subscription): CollectedPeriod;

    /**
     * Returns money against the payment that collected the period, with the
     * credit note that declares it (ADR-058).
     *
     * On its own transaction, and **before** the plan moves. The reasoning is
     * at the call site, because it is a decision about which of two
     * half-finished states this platform prefers rather than a property of
     * this interface.
     *
     * Only called when {@see CollectedPeriod::hasSomethingToReturn()} and the
     * amount is positive and within what that answer allows.
     *
     * @return string the id of the refund raised
     */
    public function giveBack(
        Subscription $subscription,
        CollectedPeriod $collected,
        int $amountMinorUnits,
        ?string $actorUserId,
    ): string;
}
