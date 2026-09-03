<?php

declare(strict_types=1);

namespace App\EInvoice\Domain;

use DateTimeImmutable;

/**
 * A delivery from the approved platform, normalised by the adapter.
 *
 * The same shape as a payment provider's event and for the same reason
 * (non-negotiable #17): nothing above the adapter knows what a particular
 * PDP called its status, so a second platform is a second adapter rather
 * than a branch.
 */
final class PlatformEvent
{
    public const SUBMITTED = 'EINVOICE_SUBMITTED';
    public const ACCEPTED = 'EINVOICE_ACCEPTED';
    public const REJECTED = 'EINVOICE_REJECTED';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $providerDocumentId,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly ?string $rejectionCode,
        public readonly ?string $rejectionReason,
        public readonly array $payload,
    ) {
    }

    public function requestedStatus(): ?string
    {
        return match ($this->type) {
            self::SUBMITTED => TransmissionStatus::SUBMITTED,
            self::ACCEPTED => TransmissionStatus::ACCEPTED,
            self::REJECTED => TransmissionStatus::REJECTED,
            default => null,
        };
    }
}
