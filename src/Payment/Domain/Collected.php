<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What a payment collects: the document it is against, and who that document
 * names (2026-09-26).
 *
 * Not on {@see Payment}, which is an attempt to move money and knows nothing
 * about people. These are the *invoice's* facts, read beside the payment so a
 * list of them can say whose each one is — which is what an administrator
 * needs and what the rows did not carry: `billing.manage` widens what is
 * shown, and twelve identical amounts with no name on them are twelve
 * identical rows.
 *
 * Read from the invoice's **snapshot**, so it says who the customer was when
 * the document was raised (§25). Resolving a current name onto a past document
 * would show a customer who has since renamed themselves under a name their
 * paper copy does not carry.
 */
final class Collected
{
    public function __construct(
        public readonly string $invoiceId,
        /**
         * The legal number — null while the invoice is still a draft, and
         * never invented. A number is allocated from a gapless sequence at
         * issue; a placeholder here is how a hole enters one.
         */
        public readonly ?string $number,
        public readonly ?string $customerName,
        public readonly ?string $customerEmail,
        /**
         * Whether the document is settled (2026-10-01).
         *
         * Reported because a **failed** attempt on a paid invoice is the
         * ordinary shape of a retry that worked: the first card was declined,
         * the second went through, and the first attempt stays failed for ever
         * because it is the record of what happened (ADR-034). Without this the
         * payments list showed a customer a red "card declined" against a bill
         * they had already paid, and gave them no way to tell.
         *
         * The invoice's own status, read beside the payment and not derived
         * from it: a screen that concluded "paid" from a sibling attempt would
         * be answering a question the server already answers, and would get it
         * wrong the moment a refund or a credit note moved the document.
         */
        public readonly bool $settled,
    ) {
    }
}
