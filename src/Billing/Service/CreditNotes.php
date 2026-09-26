<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\CreditNote;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Domain\TaxCalculation;
use App\Tax\Domain\VatTransaction;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * Issuing credit notes, which is how a finalised invoice is corrected.
 *
 * A wrong invoice is never edited and never deleted: it has a legal number in
 * an unbroken sequence, and both would leave a hole. It is credited, and both
 * documents are kept — which is why `CREDITED` has existed as an invoice
 * state since the schema was written and only now has something that can put
 * an invoice into it.
 *
 * The credit note's lines are copied from the invoice's. That is not a
 * shortcut around the snapshot rule but an application of it: the invoice's
 * lines are themselves a snapshot taken when it was issued, so copying them
 * copies what was actually charged rather than what the offer says today.
 *
 * **And so are its fiscal facts** (2026-09-26). This wrote none until today:
 * `vat_transactions.credit_note_id` was in the schema from the first
 * migration and {@see VatTransaction} said in its own docblock that "a
 * correction is a new transaction attached to a credit note", and nothing in
 * the platform ever wrote one. Credit a €1,000 + €200 invoice in full and
 * `GET /tax/reports/{period}` still declared the €200 — then closing the
 * period froze it, permanently, because a closed period is immutable.
 *
 * They are **negated copies of the invoice's own transactions**, never a
 * recalculation. §25.3 forbids recomputing historical VAT with today's rates,
 * and this is where it would happen most easily: a customer whose VAT number
 * was verified after the invoice went out would be charged 20% and credited
 * 0%, leaving the difference declared for ever.
 */
final class CreditNotes
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly InvoiceRepository $invoices,
        private readonly Taxation $taxation,
    ) {
    }

    /**
     * @return array{credit_notes: list<CreditNote>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null): array
    {
        return [
            'credit_notes' => $this->creditNotes->listForTenant($tenantId, $productId, $limit, $offset, $ownedBy),
            'total' => $this->creditNotes->countForTenant($tenantId, $productId, $ownedBy),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $creditNoteId, ?string $ownedBy = null): CreditNote
    {
        $note = $this->creditNotes->find($tenantId, $productId, $creditNoteId, $ownedBy);

        if ($note === null) {
            throw new NotFoundException('Credit note not found.', [], 'CREDIT_NOTE_NOT_FOUND');
        }

        return $note;
    }

    /**
     * Credits an invoice in full.
     *
     * Only in full, for now. A partial credit is a different document — it
     * needs the caller to choose lines or an amount, and the VAT has to be
     * apportioned across rates rather than copied — and guessing at that
     * would produce a legal document nobody asked for. Crediting in full and
     * reissuing is the correct handling of a wrong invoice anyway.
     */
    public function issue(
        string $tenantId,
        string $productId,
        string $invoiceId,
        ?string $reason,
        ?string $actorUserId,
    ): CreditNote {
        $invoice = $this->requireInvoice($tenantId, $productId, $invoiceId);

        // The state machine decides, so crediting a draft (which was never
        // sent) or an already-credited invoice is refused the same way
        // everywhere.
        InvoiceStatus::assertPermits($invoice->status, InvoiceStatus::CREDITED);

        // Read before the document exists, so a credit note is never issued
        // against an invoice whose facts cannot be found: numbering is
        // gapless and a correction raised without its reversal would have to
        // be corrected in turn.
        $reversal = self::negate($this->taxation->factsOfInvoice($invoice->id));

        // Dated now, not at the invoice's date. A correction belongs to the
        // period it is made in — putting it back where the mistake was would
        // reopen a closed period, which §25.3 forbids for the same reason
        // gapless numbering forbids deleting a document.
        $credited = new DateTimeImmutable();

        return $this->creditNotes->issue(
            $invoice,
            $invoice->lines,
            $invoice->supplier,
            $invoice->customer,
            $reason,
            $actorUserId,
            function (CreditNote $note) use ($invoice, $reversal, $credited): void {
                // Nothing to reverse is not an error: an invoice from before
                // §25.3 produced no fact, and writing a zero row would assert
                // a correction of nothing.
                foreach ($reversal as $supplyType => $calculations) {
                    $this->taxation->recordFor(
                        $invoice->tenantId,
                        $invoice->productId,
                        // The invoice's issuer, exactly as its number came
                        // from that issuer's series: a correction is filed in
                        // the return the original was filed in (ADR-054).
                        $invoice->issuerTenantId,
                        null,
                        $note->id,
                        $supplyType,
                        $credited,
                        $calculations,
                    );
                }
            },
        );
    }

    /**
     * The invoice's facts, turned round.
     *
     * Everything that describes *why* the tax was what it was — the rule, the
     * regime, the country, the rate, the customer's number and its status —
     * is copied unchanged, because that is what was true when the sale
     * happened. Only the two amounts change sign.
     *
     * Keyed by supply type, because that is the one field
     * {@see Taxation::recordFor} takes per call rather than per fact. An
     * invoice has one in practice; keying on it is what makes that an
     * observation rather than an assumption.
     *
     * @param list<VatTransaction> $facts
     *
     * @return array<string, list<TaxCalculation>>
     */
    private static function negate(array $facts): array
    {
        /** @var array<string, list<TaxCalculation>> $bySupply */
        $bySupply = [];

        foreach ($facts as $fact) {
            $bySupply[$fact->supplyType][] = new TaxCalculation(
                $fact->ruleId,
                $fact->vatRegime,
                $fact->country,
                $fact->vatRate,
                -$fact->taxableBase,
                -$fact->vatAmount,
                $fact->currency,
                $fact->reverseCharge,
                $fact->customerTaxStatus,
                $fact->customerTaxNumber,
                null,
                ['Reversal of the invoice this credit note corrects.'],
            );
        }

        return $bySupply;
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
