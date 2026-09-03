<?php

declare(strict_types=1);

namespace App\EInvoice\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\EInvoice\Domain\Transmission;
use App\EInvoice\Domain\TransmissionRepository;
use App\EInvoice\Domain\TransmissionStatus;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Transmitting invoices through an approved platform (§25.1).
 *
 * The submission is deliberately **not** one transaction, because it contains
 * a network call and holding a database transaction open across one is how a
 * connection pool dies. Instead it is resumable: the transmission row is
 * opened first, and a crash before the platform answers leaves a PENDING row
 * that the next attempt picks up rather than a second document lodged with
 * the platform.
 *
 * The verdict, when it arrives, *is* one transaction — that is the webhook,
 * and it works exactly like the payment one.
 */
final class EInvoicing
{
    public function __construct(
        private readonly TransmissionRepository $transmissions,
        private readonly InvoiceRepository $invoices,
        private readonly EInvoiceProviders $providers,
    ) {
    }

    /**
     * @return list<Transmission>
     */
    public function forInvoice(string $tenantId, string $productId, string $invoiceId): array
    {
        return $this->transmissions->forInvoice($tenantId, $productId, $invoiceId);
    }

    /**
     * Hands an invoice to the platform.
     *
     * A PENDING transmission from a previous attempt is resumed rather than
     * duplicated: it means the platform never answered, and opening a second
     * one would lodge the same document twice.
     */
    public function submit(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): Transmission
    {
        $invoice = $this->requireInvoice($tenantId, $productId, $invoiceId);
        $provider = $this->providers->default();

        $inFlight = $this->transmissions->inFlightFor($tenantId, $productId, $invoiceId);

        if ($inFlight !== null && $inFlight->status === TransmissionStatus::SUBMITTED) {
            // Lodged and awaiting a verdict. Sending it again would put two
            // documents in front of the administration for one invoice.
            throw new ConflictException(
                'EINVOICE_ALREADY_IN_FLIGHT',
                'That invoice is already lodged with the platform and awaiting a verdict.',
                ['transmission_id' => $inFlight->id],
            );
        }

        $transmission = $inFlight ?? $this->openFor($invoice, $provider->name(), $actorUserId);

        // The network call, outside any transaction.
        $document = $provider->submit($invoice);

        $submitted = $this->transmissions->recordSubmission($transmission, $document);

        $current = $this->requireInvoice($tenantId, $productId, $invoiceId);

        if (InvoiceStatus::permits($current->status, InvoiceStatus::SUBMITTED)) {
            $this->invoices->transition($current, InvoiceStatus::SUBMITTED, $actorUserId);
        }

        return $submitted;
    }

    /**
     * Marks the invoice ready and opens the transmission that will carry it.
     *
     * READY_FOR_EINVOICE is a recorded step rather than a formality: an
     * invoice reaches SUBMITTED only through it, so the intent to transmit is
     * always visible even when the platform never answers.
     */
    private function openFor(Invoice $invoice, string $provider, ?string $actorUserId): Transmission
    {
        if ($invoice->status === InvoiceStatus::ISSUED || $invoice->status === InvoiceStatus::REJECTED) {
            $this->invoices->transition($invoice, InvoiceStatus::READY_FOR_EINVOICE, $actorUserId);
        } elseif ($invoice->status !== InvoiceStatus::READY_FOR_EINVOICE) {
            throw new ConflictException(
                'INVOICE_NOT_TRANSMITTABLE',
                'Only an issued or rejected invoice can be transmitted.',
                ['status' => $invoice->status],
            );
        }

        return $this->transmissions->open($invoice->tenantId, $invoice->productId, $invoice->id, $provider);
    }

    private function requireInvoice(string $tenantId, string $productId, string $invoiceId): Invoice
    {
        $invoice = $this->invoices->find($tenantId, $productId, $invoiceId);

        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.', [], 'INVOICE_NOT_FOUND');
        }

        return $invoice;
    }
}
