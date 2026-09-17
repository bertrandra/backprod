<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Payment\Domain\Payment;
use DateTimeImmutable;

/**
 * What an invoice's document says about where the money stands, composed at
 * download time (docs/tenant-roots.md §2.5).
 *
 * The stored PDF is the legal document and never changes: what was issued
 * is what was issued (§25). Payment is not part of its content, so it is a
 * band on top of page one, composed from `invoices.status` and the settling
 * payment each time the document is served — the same facts the screen
 * shows. An invoice is generated before payment, so a document with no word
 * on the matter reads as a bill to somebody who has paid and as a receipt
 * to somebody who has not.
 *
 * French, like the document. "Payée le …" with the reference rather than
 * the customary "acquittée": the date and the reference are what an auditor
 * asks for.
 */
final class InvoiceStatusBand
{
    public const PENDING = 'PENDING';
    public const OVERDUE = 'OVERDUE';
    public const PAID = 'PAID';
    public const CANCELLED = 'CANCELLED';
    public const CREDITED = 'CREDITED';

    private function __construct(
        public readonly string $kind,
        public readonly string $text,
    ) {
    }

    public static function for(Invoice $invoice, ?Payment $settling, DateTimeImmutable $now): self
    {
        switch ($invoice->status) {
            case InvoiceStatus::PAID:
                $when = $invoice->paidAt ?? $settling?->succeededAt;
                $text = 'PAYÉE' . ($when === null ? '' : ' LE ' . self::day($when));

                if ($settling !== null) {
                    $text .= ' — ' . self::instrument($settling->method) . ', RÉF. ' . $settling->providerPaymentId;
                }

                return new self(self::PAID, $text);

            case InvoiceStatus::CANCELLED:
                return new self(self::CANCELLED, 'ANNULÉE');

            case InvoiceStatus::CREDITED:
                return new self(self::CREDITED, 'CRÉDITÉE — UN AVOIR A ÉTÉ ÉMIS');

            default:
                if ($invoice->dueAt !== null && $invoice->dueAt < $now) {
                    return new self(self::OVERDUE, 'IMPAYÉE — ÉCHÉANCE DÉPASSÉE LE ' . self::day($invoice->dueAt));
                }

                return new self(
                    self::PENDING,
                    'EN ATTENTE DE PAIEMENT' . ($invoice->dueAt === null ? '' : ' — ÉCHÉANCE LE ' . self::day($invoice->dueAt)),
                );
        }
    }

    private static function day(DateTimeImmutable $moment): string
    {
        return $moment->format('d/m/Y');
    }

    private static function instrument(?string $method): string
    {
        return match (strtoupper((string) $method)) {
            'CARD' => 'PAR CARTE',
            'SEPA_DEBIT', 'SEPA' => 'PAR PRÉLÈVEMENT',
            'TRANSFER', 'BANK_TRANSFER' => 'PAR VIREMENT',
            '' => 'RÉGLÉE',
            default => 'PAR ' . strtoupper((string) $method),
        };
    }
}
