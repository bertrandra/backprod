<?php

declare(strict_types=1);

namespace App\EInvoice\Controller;

use App\EInvoice\Domain\Transmission;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for transmissions.
 *
 * `provider_document_id` is included because it is the thing §25.1 requires
 * be kept, and the whole point of keeping it is that somebody can quote it
 * when proving an invoice was transmitted.
 */
final class TransmissionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Transmission $transmission): array
    {
        return [
            'id' => $transmission->id,
            'invoice_id' => $transmission->invoiceId,
            'provider' => $transmission->provider,
            'provider_document_id' => $transmission->providerDocumentId,
            'status' => $transmission->status,
            'settled' => $transmission->isSettled(),
            'rejection_code' => $transmission->rejectionCode,
            'rejection_reason' => $transmission->rejectionReason,
            'submitted_at' => self::nullableMoment($transmission->submittedAt),
            'settled_at' => self::nullableMoment($transmission->settledAt),
            'created_at' => self::moment($transmission->createdAt),
        ];
    }

    /**
     * @param list<Transmission> $transmissions
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $transmissions): array
    {
        return array_map(self::one(...), $transmissions);
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
