<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Tax\Domain\TaxCalculation;

/**
 * What a credit note about to be raised will say, decided before anything is
 * written (2026-09-26).
 *
 * It exists because of the order two things have to happen in. A refund is
 * money leaving through a provider, and once the provider has been asked
 * there is no taking it back; a credit note is the document that makes that
 * money legal, and it can be refused — an invoice carrying several VAT rates
 * cannot be credited in part. Deciding the document first and writing it
 * inside the refund's own transaction is what keeps "the money went but
 * nothing declares it" from being a state this platform can reach.
 *
 * `inFull` and `closesTheInvoice` are not the same question, and conflating
 * them is easy:
 *
 * ```text
 * inFull            the document copies the invoice's lines and negates all
 *                   its fiscal facts wholesale
 * closesTheInvoice  nothing of the invoice is left uncredited, so it moves
 *                   to CREDITED
 * ```
 *
 * A second partial credit finishing off an invoice closes it without being
 * in full: the document describes its own share, the invoice is nonetheless
 * undone. `inFull` implies `closesTheInvoice`, never the reverse.
 */
final class CreditNotePlan
{
    /**
     * @param list<InvoiceLine>                  $lines
     * @param array<string, list<TaxCalculation>> $reversal by supply type, which is
     *                                                      the one field the tax
     *                                                      recorder takes per call
     *                                                      rather than per fact
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $inFull,
        public readonly bool $closesTheInvoice,
        public readonly array $lines,
        public readonly array $reversal,
    ) {
    }
}
