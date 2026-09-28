<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\CreditNote;
use App\Billing\Domain\CreditNotePlan;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\DocumentPeople;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Shared\Exceptions\ConflictException;
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
 *
 * ## Crediting part of an invoice (2026-09-26)
 *
 * This credited in full only, and said so: a partial credit "needs the caller
 * to choose lines or an amount, and the VAT has to be apportioned across
 * rates rather than copied — and guessing at that would produce a legal
 * document nobody asked for."
 *
 * That rule is kept, and it is why the partial credit that now exists is
 * **bounded to one VAT rate** and refuses — `CREDIT_NOTE_MULTIPLE_RATES` —
 * on an invoice carrying several. The apportionment nobody could defend is
 * still not performed; it is declined. One seat, one plan, one rate is the
 * case that matters, and it has no apportionment in it.
 *
 * What made it necessary is the other half of the same hole. `Payments`
 * gives money back and, until today, wrote no document at all: the fiscal
 * fact of the invoice stayed declared while the money had gone. Dormant
 * while refunds are rare, and systematic the moment a proration credit goes
 * back on the card — so a refund now carries its credit note, planned before
 * the provider is asked and written on the refund's own transaction.
 *
 * No caller may simply name an amount: {@see planFor} takes one because a
 * refund has one, and the refund's amount is bounded by what was collected.
 * There is no endpoint for "credit me €7 of this".
 */
final class CreditNotes
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly InvoiceRepository $invoices,
        private readonly Taxation $taxation,
        private readonly DocumentPeople $people,
    ) {
    }

    /**
     * A page of them — the caller's own when `$ownedBy` is set, one person's
     * when `$person` is, inside the window `$from`..`$to` — and whom each
     * concerns (2026-09-27, the window 2026-09-28), so the list can name the
     * person beside every document.
     *
     * @return array{credit_notes: list<CreditNote>, people: array<string, array{user_id: string, name: string|null, email: string|null}>, total: int, limit: int, offset: int}
     */
    public function list(
        string $tenantId,
        string $productId,
        int $limit,
        int $offset,
        ?string $ownedBy = null,
        ?string $person = null,
        ?string $status = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $page = $this->creditNotes->listForTenant($tenantId, $productId, $limit, $offset, $ownedBy, $person, $status, $from, $to);

        return [
            'credit_notes' => $page,
            'people' => $this->people->ofCreditNotes($tenantId, array_map(static fn ($document): string => $document->id, $page)),
            'total' => $this->creditNotes->countForTenant($tenantId, $productId, $ownedBy, $person, $status, $from, $to),
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
     * Only in full **from here**. A partial credit is a different document,
     * and {@see planFor} below says on what narrow terms one may exist:
     * nobody may ask for one by choosing an amount, because choosing an
     * amount is the part that would need the VAT apportioned by guess.
     */
    public function issue(
        string $tenantId,
        string $productId,
        string $invoiceId,
        ?string $reason,
        ?string $actorUserId,
    ): CreditNote {
        $invoice = $this->requireInvoice($tenantId, $productId, $invoiceId);

        // The whole document, which also asks the state machine whether this
        // invoice may be credited at all.
        $plan = $this->planFor($invoice, $invoice->gross);

        return $this->creditNotes->issue(
            $invoice,
            $plan->lines,
            $invoice->supplier,
            $invoice->customer,
            $reason,
            $actorUserId,
            $plan->closesTheInvoice,
            $this->recording($plan, new DateTimeImmutable()),
        );
    }

    /**
     * What crediting an invoice by an amount would produce — decided, and
     * refused where it cannot be, **before anything is written**
     * (2026-09-26).
     *
     * A refund calls this first and then asks the provider for the money,
     * because the two refusals cost different things: a plan refused costs
     * nothing, and money already sent cannot be unsent. It then writes the
     * document this returns inside the refund's own transaction, so a
     * refund without its credit note is not a state this platform can reach
     * — the same rule as the early-termination charge, raised on the
     * cancellation's own transaction.
     *
     * The partial credit is bounded on three sides:
     *
     * - it may not credit more of an invoice than is left uncredited;
     * - it needs the invoice to carry **one** VAT rate, and refuses on one
     *   carrying several rather than apportioning between them by guess —
     *   which is the rule this class has always held and is not being worked
     *   around here;
     * - it reverses at the rate the invoice's own fiscal fact **recorded**,
     *   never at today's, because §25.3 forbids recomputing historical VAT
     *   with current rates and this is where that would happen most easily.
     */
    public function planFor(Invoice $invoice, Money $amount): CreditNotePlan
    {
        if ($amount->isNegative()) {
            throw new ConflictException(
                'CREDIT_AMOUNT_INVALID',
                'A credit note cannot be for a negative amount.',
                ['requested' => $amount->minorUnits],
            );
        }

        // First, and for both shapes of credit. A draft was never sent and an
        // already-credited invoice has had its facts reversed once; neither
        // may be credited again, in part any more than in full. The state
        // machine gives one answer to that question everywhere.
        InvoiceStatus::assertPermits($invoice->status, InvoiceStatus::CREDITED);

        $credited = $this->creditNotes->creditedOn($invoice->id);

        if ($credited + $amount->minorUnits > $invoice->gross->minorUnits) {
            // Nothing else bounds this. Credit an invoice in full and then
            // refund the card, and without this the platform reverses the
            // same VAT twice and declares a negative sale that never
            // happened.
            throw new ConflictException(
                'CREDIT_EXCEEDS_INVOICE',
                'That would credit more than the invoice charged.',
                [
                    'invoiced' => $invoice->gross->minorUnits,
                    'already_credited' => $credited,
                    'requested' => $amount->minorUnits,
                ],
            );
        }

        // Read before the document exists, so a credit note is never issued
        // against an invoice whose facts cannot be found: numbering is
        // gapless and a correction raised without its reversal would have to
        // be corrected in turn.
        $facts = $this->taxation->factsOfInvoice($invoice->id);

        // Nothing of the invoice left standing, so it is undone — whether
        // this credit is the whole of it or the last slice of it.
        $closes = $credited + $amount->minorUnits === $invoice->gross->minorUnits;

        if ($credited === 0 && $closes) {
            return new CreditNotePlan($invoice, true, true, $invoice->lines, self::negate($facts));
        }

        return self::partial($invoice, $amount, $facts, $closes);
    }

    /**
     * Writes a planned credit note **inside the caller's transaction**.
     *
     * Paired with {@see planFor}, and deliberately unable to decide anything
     * of its own: everything that could be refused was refused before the
     * money moved.
     */
    public function applyPlan(CreditNotePlan $plan, ?string $reason, ?string $actorUserId): CreditNote
    {
        $invoice = $plan->invoice;

        return $this->creditNotes->applyIssue(
            $invoice,
            $plan->lines,
            $invoice->supplier,
            $invoice->customer,
            $reason,
            $actorUserId,
            $plan->closesTheInvoice,
            $this->recording($plan, new DateTimeImmutable()),
        );
    }

    /**
     * The callback that writes the reversing fiscal facts, inside whatever
     * transaction is issuing the document.
     *
     * `$credited` is **now**, not the invoice's date. A correction belongs to
     * the period it is made in — putting it back where the mistake was would
     * reopen a closed period, which §25.3 forbids for the same reason gapless
     * numbering forbids deleting a document.
     *
     * @return callable(CreditNote): void
     */
    private function recording(CreditNotePlan $plan, DateTimeImmutable $credited): callable
    {
        $invoice = $plan->invoice;

        return function (CreditNote $note) use ($invoice, $plan, $credited): void {
            // Nothing to reverse is not an error: an invoice from before
            // §25.3 produced no fact, and writing a zero row would assert
            // a correction of nothing.
            foreach ($plan->reversal as $supplyType => $calculations) {
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
        };
    }

    /**
     * A credit note for part of an invoice.
     *
     * **One rate, or nothing.** The apportionment this class refuses to guess
     * at does not arise on the case that matters — one seat, one plan, one
     * rate — and on the case where it does arise, the answer stays what it
     * was: credit in full and reissue. So the refusal is explicit and names
     * what it found, rather than a division nobody could defend to an
     * auditor.
     *
     * **The money is exact and the base absorbs the rounding.** The amount is
     * a gross figure that has already left, so `net + vat` must equal it to
     * the minor unit — the database says as much in
     * `credit_notes_gross_is_net_plus_vat`. The base is taken back out of it
     * at the rate ({@see TaxCalculation::baseOfGross}) and the VAT is the
     * remainder, never a second rounding of its own, which would leave a cent
     * belonging to neither. Several partial credits of one invoice can
     * therefore split base and VAT a cent differently from one credit of the
     * total; each document is exact against the movement it describes, which
     * is the property an audit asks about.
     *
     * **Nothing is recomputed.** The rate, the rule, the regime, the country
     * of taxation and the customer's number come from the invoice's own
     * fiscal fact, as values (ADR-057). A customer whose number was verified
     * after the invoice went out was charged 20% and would be credited 0%,
     * leaving the difference declared for ever.
     *
     * @param list<VatTransaction> $facts
     */
    private static function partial(
        Invoice $invoice,
        Money $amount,
        array $facts,
        bool $closes,
    ): CreditNotePlan {
        if ($amount->minorUnits <= 0) {
            throw new ConflictException(
                'CREDIT_AMOUNT_INVALID',
                'A partial credit note must be for a positive amount.',
                ['requested' => $amount->minorUnits],
            );
        }

        $rates = [];

        foreach ($invoice->lines as $line) {
            $rates[$line->vatRateBasisPoints] = true;
        }

        if (count($rates) > 1 || count($facts) > 1) {
            // Two facts at one rate is the same refusal for the same reason:
            // which of them a part-credit belongs to is a question only the
            // caller could answer, and it is not being asked one.
            throw new ConflictException(
                'CREDIT_NOTE_MULTIPLE_RATES',
                'This invoice carries more than one VAT rate, so part of it cannot be credited '
                . 'without apportioning the tax by guess. Credit it in full and reissue.',
                [
                    'rates_basis_points' => array_map(intval(...), array_keys($rates)),
                    'fiscal_facts' => count($facts),
                ],
            );
        }

        $fact = $facts[0] ?? null;

        // The recorded rate when there is one, and only otherwise the
        // document's own — an invoice from before §25.3 left no fact, so
        // there is nothing to reverse and the rate merely describes the
        // document.
        $rate = $fact !== null ? $fact->vatRate : (int) (array_key_first($rates) ?? 0);

        $net = Money::of(TaxCalculation::baseOfGross($amount->minorUnits, $rate), $amount->currency);
        $vat = $amount->minus($net);

        // Built by hand rather than through InvoiceLine::of(), which would
        // derive the VAT from the base and could land a minor unit away from
        // the money that actually moved.
        $line = new InvoiceLine(
            1,
            sprintf('Partial credit against invoice %s', $invoice->number ?? $invoice->id),
            1,
            $net,
            Money::zero($amount->currency),
            $net,
            $rate,
            $vat,
            $amount,
            null,
        );

        $reversal = $fact === null ? [] : [
            $fact->supplyType => [
                new TaxCalculation(
                    $fact->ruleId,
                    $fact->vatRegime,
                    $fact->country,
                    $fact->vatRate,
                    -$net->minorUnits,
                    -$vat->minorUnits,
                    $amount->currency,
                    $fact->reverseCharge,
                    $fact->customerTaxStatus,
                    $fact->customerTaxNumber,
                    null,
                    ['Partial reversal of the invoice this credit note corrects.'],
                ),
            ],
        ];

        return new CreditNotePlan($invoice, false, $closes, [$line], $reversal);
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
