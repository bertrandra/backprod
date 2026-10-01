<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * The next paid period, billed as a real document (§13.1, §25, ADR-068).
 *
 * A port for the same reason {@see EarlyTerminationCharge} is one: the module
 * that owns the subscription must not know how an invoice is made. Commerce says
 * "bill this period" and the sales layer decides that this means a numbered,
 * dated, taxed document priced from the version the customer actually holds.
 *
 * **Required, not optional.** `renew()` moved the period and the entitlements
 * forward and raised nothing at all, which was harmless only while nothing
 * called it: the one caller was a service method with no endpoint and no job. The
 * moment anything renewed on its own, every subsequent period of every
 * subscription would have been given away. So billing is an argument of renewing
 * rather than a step beside it — a caller that forgets does not compile, which is
 * the rule `Reach` is required for and the one ADR-066 restated.
 */
interface RenewalCharge
{
    /**
     * Raises the invoice for the period starting now, inside the renewal's
     * transaction.
     *
     * Participating, never transactional. The period moving and the invoice for
     * it are one fact: a period extended unbilled is revenue given away, and an
     * invoice for a period the subscription never got is a customer charged for
     * nothing. Neither may be observable, not even after a crash between them.
     *
     * @param DateTimeImmutable $periodStart where the new period begins — the old
     *                                       one's end, never now, or a renewal an
     *                                       hour late bills from the wrong day
     *
     * @return string the id of the document raised
     */
    public function applyRenewal(
        Subscription $subscription,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        ?string $actorUserId,
    ): string;
}
