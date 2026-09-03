<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

use DateTimeImmutable;

/**
 * One attempt to transmit an invoice through an approved platform.
 *
 * §25.1 requires the identifiers and statuses the platform supplies to be
 * kept. One row per attempt rather than one per invoice, so a rejection
 * followed by a corrected resubmission leaves both visible: an inspector
 * asking "was this transmitted?" is owed the whole history, not the latest
 * answer.
 */
final class Transmission
{
    public function __construct(
        public readonly string $id,
        public readonly string $invoiceId,
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $provider,
        public readonly ?string $providerDocumentId,
        public readonly string $status,
        public readonly ?string $rejectionCode,
        public readonly ?string $rejectionReason,
        public readonly ?DateTimeImmutable $submittedAt,
        public readonly ?DateTimeImmutable $settledAt,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function permits(string $status): bool
    {
        return TransmissionStatus::permits($this->status, $status);
    }

    public function isSettled(): bool
    {
        return TransmissionStatus::isSettled($this->status);
    }
}
