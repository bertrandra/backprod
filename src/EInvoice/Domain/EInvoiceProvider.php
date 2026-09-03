<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

use App\Billing\Domain\Invoice;
use App\Shared\Exceptions\UnauthenticatedException;

/**
 * Port for an approved e-invoicing platform — a PDP (§25.1).
 *
 * Non-negotiable #17 requires the choice of platform to stay interchangeable,
 * and §25.1 is explicit that the gateway must not be built into the SaaS.
 * So this is the same shape as the payment provider port, and for the same
 * reasons: verification takes the raw bytes, nothing above it knows what a
 * particular platform called its statuses, and a second PDP is a second
 * adapter.
 */
interface EInvoiceProvider
{
    public function name(): string;

    /**
     * Hands an invoice to the platform and returns its identifier for the
     * document.
     *
     * The invoice is passed whole because §25.1 lists what a transmitted
     * document must carry — supplier, customer, SIREN, lines, rates — and
     * every one of those is already on the invoice's own snapshot. An adapter
     * maps that onto whatever format its platform wants.
     */
    public function submit(Invoice $invoice): SubmittedDocument;

    /**
     * @param array<array-key, string> $headers
     *
     * @throws UnauthenticatedException when the signature is absent, malformed or wrong
     */
    public function verify(string $rawBody, array $headers): void;

    /**
     * Normalises a verified delivery, or null when it describes something
     * this platform does not model — which must not be answered with an
     * error, or the platform will retry it forever.
     */
    public function parse(string $rawBody): ?PlatformEvent;
}
