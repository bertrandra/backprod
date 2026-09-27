<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * One person who must be chased, about one unpaid invoice (spec §5.2).
 *
 * A row per **recipient** rather than per subscription, exactly as
 * {@see RenewalNotice} is: the point of a chase is that somebody who can act on
 * it hears about it, and for an organisation's subscription that is every
 * administrator rather than the abstraction holding the contract.
 *
 * `recipientUserId` is nullable for the same reason it is there: a subscription
 * whose tenant has no administrator is owed for and has nobody to tell, and
 * returning no row would make that read as nothing to collect. A debt nobody
 * was told about is the failure worth surfacing, not the one worth hiding.
 *
 * `daysOverdue` is computed by PostgreSQL from the invoice's own dates, whole
 * days, never in PHP: the clock that decides which chase is due has to be the
 * same clock everything else here is measured against.
 */
final class OverdueSubscription
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $invoiceId,
        /** The invoice's legal number; null only for a document still in draft, which is never collectable. */
        public readonly ?string $invoiceNumber,
        public readonly DateTimeImmutable $dueAt,
        public readonly int $daysOverdue,
        /** Whether it has already been declared in arrears, so the declaration is written once. */
        public readonly bool $alreadyInArrears,
        public readonly ?string $recipientUserId,
    ) {
    }

    /**
     * One chase per step per invoice, for ever.
     *
     * The **invoice** and the step, not the subscription: a subscription owed
     * for twice over is two debts and two conversations, and keying on the
     * subscription would silence the second one. The step is in the key because
     * J+1, J+3 and J+7 are three notices about the same debt — that is what a
     * schedule *is* — and keying on the invoice alone would send the first and
     * swallow the rest.
     *
     * Read by {@see \App\Notification\Service\Notifications::raise()}, where the
     * unique index decides rather than a prior check: two runners each see no
     * notice and both write, and one of them is a second mail.
     */
    public function dedupKey(int $step): string
    {
        return $this->invoiceId . ':' . $step;
    }
}
