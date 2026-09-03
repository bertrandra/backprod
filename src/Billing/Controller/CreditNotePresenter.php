<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Domain\CreditNote;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for credit notes.
 *
 * Amounts are positive and `direction` says which way the money goes, so a
 * client summing documents does not have to know that one kind of them is
 * secretly negative.
 */
final class CreditNotePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(CreditNote $note): array
    {
        return [
            'id' => $note->id,
            'number' => $note->number,
            'invoice_id' => $note->invoiceId,
            'direction' => 'CREDIT',
            'reason' => $note->reason,
            'net' => ['minor_units' => $note->net->minorUnits, 'currency' => $note->net->currency],
            'vat' => ['minor_units' => $note->vat->minorUnits, 'currency' => $note->vat->currency],
            'gross' => ['minor_units' => $note->gross->minorUnits, 'currency' => $note->gross->currency],
            'issued_at' => $note->issuedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339),
            'supplier' => $note->supplier,
            'customer' => $note->customer,
            'lines' => InvoicePresenter::lines($note->lines),
        ];
    }

    /**
     * @param list<CreditNote> $notes
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $notes): array
    {
        return array_map(self::one(...), $notes);
    }
}
