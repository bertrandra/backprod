<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Who sells, who buys, and under whose VAT regime — resolved once
 * (2026-09-25).
 *
 * The three travel together because they are one decision. A document whose
 * supplier block names the organisation, whose number came from the
 * platform's series and whose VAT was filed in the platform's country would
 * be a document neither party can account for, and the only way that cannot
 * happen is for nothing to be able to answer one of these without the others.
 */
final class InvoiceParties
{
    /**
     * @param array<string, mixed> $from the supplier's identity, snapshotted
     * @param array<string, mixed> $to   the customer's identity, snapshotted
     */
    public function __construct(
        /**
         * The organisation issuing the document, whose gapless series the
         * number comes from (ADR-054); null when the platform issues it.
         */
        public readonly ?string $issuerTenantId,
        public readonly array $from,
        public readonly array $to,
        /** The country whose VAT is charged: the supplier's. */
        public readonly string $jurisdiction,
    ) {
    }
}
