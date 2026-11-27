<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Withdraws the invoice of a period that never began (ADR-079).
 *
 * A port for the reason {@see EarlyTerminationCharge} and {@see ChangeCredit}
 * are: subscriptions must not learn how an invoice is cancelled, how VAT is
 * reversed or how money goes back.
 *
 * **Two moments, because a provider cannot be asked inside a transaction and
 * unasked afterwards.**
 *
 * `giveBack` runs first, on its own. If the invoice was already paid, the money
 * goes back through the ordinary refund chain — the credit note planned before
 * the provider is asked and written on the refund's own transaction (ADR-058).
 * If it was not, any attempt still open is abandoned at the provider, so the
 * document is not settled behind the cancellation's back.
 *
 * `applyCancel` then runs **on the cancellation's own transaction**, for an
 * invoice still unpaid: it is cancelled, with the reversal of the VAT it
 * declared, in the same act as the subscription being released. A release with
 * the invoice still owed is a customer chased for a period they cancelled.
 */
interface UnstartedPeriodRelease
{
    /**
     * Before the transaction. Answers the period with what became of its
     * invoice: {@see UnstartedPeriod::REFUNDED}, or left as it was when the
     * invoice is unpaid and is to be cancelled inside the transaction.
     */
    public function giveBack(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod;

    /**
     * Inside the transaction. Cancels the invoice when it is still unpaid, and
     * answers the period saying so; does nothing for one already refunded.
     */
    public function applyCancel(Subscription $subscription, UnstartedPeriod $period, ?string $actorUserId): UnstartedPeriod;
}
