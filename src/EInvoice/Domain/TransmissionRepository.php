<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

/**
 * Transmissions and the platform deliveries that move them.
 */
interface TransmissionRepository
{
    /**
     * @return list<Transmission>
     */
    public function forInvoice(string $tenantId, string $productId, string $invoiceId): array;

    public function find(string $tenantId, string $productId, string $transmissionId): ?Transmission;

    public function findByDocument(string $provider, string $providerDocumentId): ?Transmission;

    /**
     * The attempt currently in flight for an invoice, if any.
     *
     * Used to refuse a second submission while one is outstanding: two
     * transmissions of the same invoice racing each other would leave the
     * platform holding two documents nobody meant to send.
     */
    public function inFlightFor(string $tenantId, string $productId, string $invoiceId): ?Transmission;

    public function open(string $tenantId, string $productId, string $invoiceId, string $provider): Transmission;

    public function recordSubmission(Transmission $transmission, SubmittedDocument $document): Transmission;

    /**
     * Records a platform delivery and applies it, in one transaction.
     *
     * Same contract as the payment webhook: the delivery is inserted against
     * a unique (provider, event id), so a replay is refused by the index
     * before the work beside it can happen twice. Implementations report a
     * replay as an outcome rather than raising, because a platform retries
     * anything that is not a 2xx.
     */
    public function apply(
        PlatformEvent $event,
        string $provider,
        ?Transmission $transmission,
        TransmissionEffect $effect,
    ): TransmissionOutcome;
}
