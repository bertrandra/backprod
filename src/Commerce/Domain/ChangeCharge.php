<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * The new period of a changed subscription, priced and invoiced (spec §3).
 *
 * A port rather than a call into billing, for the reason every port here
 * exists: the module that owns the subscription must not know how an invoice
 * is made. Commerce says what it needs — "what does this period cost, and
 * raise the document for it" — and something in the sales layer decides that
 * this means a numbered, taxed invoice priced from the version being moved to.
 *
 * The same shape as {@see EarlyTerminationCharge}, which raises the other
 * document a subscription's own lifecycle produces.
 *
 * **Two methods, one code path.** {@see quote} answers the preview and
 * {@see applyCharge} raises the document, and an implementation must derive
 * both from the same line — otherwise the figure a screen shows and the figure
 * a customer is charged are two answers to one question, which is the property
 * §25.3 protects by making `/tax/calculate` share its code with invoicing.
 */
interface ChangeCharge
{
    /**
     * What the new period would be invoiced at, **gross**, in minor units.
     *
     * Gross because that is what a card is charged and what the credit it is
     * set against is measured in. A pre-tax figure here would make the net
     * wrong by exactly the VAT.
     *
     * Read-only: it raises no document, allocates no number and writes
     * nothing, so a preview costs a customer nothing.
     */
    public function quote(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $at,
    ): int;

    /**
     * Raises the invoice for the new period, inside the transaction that is
     * changing the offer.
     *
     * Participating, never transactional — the same rule as the
     * early-termination charge, and for the same reason: a subscription moved
     * onto a dearer plan with its period unbilled is revenue given away, and
     * an invoice for a period the subscription never entered is a customer
     * charged for something they did not get. Neither may be observable, not
     * even after a crash between them.
     *
     * @param DateTimeImmutable $periodStart the new anchor: the change is what
     *                                       opens the period being billed
     *
     * @return string the id of the document raised
     */
    public function applyCharge(
        Subscription $subscription,
        SubscribedOffer $offer,
        DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string;
}
