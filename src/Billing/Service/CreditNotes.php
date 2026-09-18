<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\CreditNote;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Shared\Exceptions\NotFoundException;

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
 */
final class CreditNotes
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly InvoiceRepository $invoices,
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

        return $this->creditNotes->issue(
            $invoice,
            $invoice->lines,
            $invoice->supplier,
            $invoice->customer,
            $reason,
            $actorUserId,
        );
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
