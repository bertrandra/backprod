<?php

declare(strict_types=1);

namespace App\Tax\Domain;

/**
 * Verification of a VAT number (§25.3), as a port.
 *
 * VIES is one adapter. The domain never calls it directly, for the reason
 * every other provider sits behind an interface here — `PaymentProvider`,
 * `EInvoiceProvider`, `StorageProvider`, `Notifier`.
 *
 * The contract is deliberately three-valued rather than boolean. "Valid",
 * "invalid" and "could not ask" are three different facts, and collapsing the
 * third into either of the first two is how a service outage turns into
 * either a wrongly zero-rated invoice or a wrongly rejected customer.
 */
interface VatNumberValidator
{
    public function check(string $vatNumber): VatNumberCheck;
}
