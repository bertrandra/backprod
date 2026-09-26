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
    ) {
    }
}
