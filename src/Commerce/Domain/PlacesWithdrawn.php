<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Telling people that a change of plan took their place (ADR-077).
 *
 * A subscription that moves to an offer selling fewer places stops covering
 * the people beyond the new number — the operator's decision of 2026-11-27 —
 * and nobody finds that out from a refusal: the holder is told who, and each
 * person is told themselves.
 *
 * A port, for the reason {@see ChangeCharge} is one: Commerce says what
 * happened and does not learn how a notice is raised. Called on the
 * transaction that moved the plan, so the places, the plan and the intent to
 * tell commit together; the sending itself goes through the queue (§27.1).
 */
interface PlacesWithdrawn
{
    /**
     * @param list<SubscriptionMember> $withdrawn  never empty
     * @param int                      $placesSold what the offer now held sells, the holder included
     */
    public function tell(Subscription $subscription, array $withdrawn, int $placesSold): void;
}
