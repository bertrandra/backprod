<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\BillingProfile;
use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoicePaid;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Commerce\Service\Subscriptions;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tax\Service\Taxation;
use DateTimeImmutable;

/**
 * Raising and settling invoices for one tenant and product.
 *
 * The rule this whole module exists to honour is §25's: an invoice is a
 * snapshot, never a view. Everything variable — who the parties are, what
 * the line was called, what it cost, what rate applied — is copied here, at
 * issue time, into rows that no later change to a profile or an offer can
 * reach. A subscription's offer version can be superseded the next morning
 * and last night's invoice still reads exactly as it was sent.
 *
 * What is deliberately not here is a scheduler. Nothing in this milestone
 * decides *when* to bill; M7's jobs do, and they call this.
 */
final class Invoicing
{
    /**
     * The mandatory payment-terms mention. A constant rather than a
     * configured string for now, because getting it wrong is a legal problem
     * and one wrong value everywhere is easier to correct than one wrong
     * value per product nobody can find.
     */
    private const PAYMENT_TERMS = 'Payable on receipt.';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePaid $paid,
        private readonly BillingProfileRepository $profiles,
        private readonly Subscriptions $subscriptions,
        private readonly Taxation $taxation,
        private readonly SupplierIdentity $supplier,
    ) {
    }

    /**
     * @return array{invoices: list<Invoice>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null): array
    {
        return [
            'invoices' => $this->invoices->listForTenant($tenantId, $productId, $limit, $offset, $ownedBy),
            'total' => $this->invoices->countForTenant($tenantId, $productId, $ownedBy),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $invoiceId, ?string $ownedBy = null): Invoice
    {
        $invoice = $this->invoices->find($tenantId, $productId, $invoiceId, $ownedBy);

        if ($invoice === null) {
            // Scoped by tenant and product in the query, so an invoice
            // belonging to somebody else is indistinguishable from one that
            // does not exist. That is the intended answer: confirming
            // existence would leak that a competitor is a customer.
            throw new NotFoundException('Invoice not found.', [], 'INVOICE_NOT_FOUND');
        }

        return $invoice;
    }

    public function profile(string $tenantId): ?BillingProfile
    {
        return $this->profiles->find($tenantId);
    }

    public function saveProfile(BillingProfile $profile): BillingProfile
    {
        return $this->profiles->save($profile);
    }

    /**
     * Bills the tenant's current subscription for its current period.
     *
     * One line, from the offer version the tenant actually holds — its name
     * and its price as they stand at this instant, copied. The version's id
     * goes onto the line for lineage, and no amount is ever read back
     * through it.
     */
    public function issueForSubscription(string $tenantId, string $productId, ?string $actorUserId): Invoice
    {
        $subscription = $this->subscriptions->current($tenantId, $productId);

        if ($subscription === null) {
            throw new NotFoundException(
                'This tenant has no active subscription to invoice.',
                [],
                'NO_SUBSCRIPTION',
            );
        }

        $profile = $this->profiles->find($tenantId);

        if ($profile === null) {
            // A customer with no legal identity cannot be invoiced. Refusing
            // before a number is allocated matters: numbering is gapless, so
            // a document raised by mistake cannot simply be deleted.
            throw new ConflictException(
                'BILLING_PROFILE_REQUIRED',
                'This tenant has no billing profile, so no invoice can be issued to it.',
            );
        }

        $supplier = $this->supplier->forProduct($productId);
        $version = $subscription->offer->version;

        // One moment for the whole issue, passed explicitly rather than taken
        // twice. Reading the clock again inside the transaction could land on
        // the far side of a rate window and put a rate on the fiscal fact
        // that the invoice line does not carry.
        $issuedAt = new DateTimeImmutable();

        // §25.3: the rate comes from a *motivated* decision — who the
        // customer is, whether their number was verified, what is supplied
        // and where it is taxed — never from a country code alone.
        $calculation = $this->taxation->calculate(
            $tenantId,
            $productId,
            $version->priceMinorUnits,
            $version->currency,
            null,
            $issuedAt,
        );

        $line = InvoiceLine::of(
            1,
            $subscription->offer->lineDescription(),
            1,
            Money::of($version->priceMinorUnits, $version->currency),
            Money::zero($version->currency),
            $calculation->rateBasisPoints,
            $version->id,
        );

        // Derived from the line just built, not from the calculation that
        // built it. They agree here by construction, and routing both paths
        // through the same helper keeps that true if a second line is ever
        // added.
        $facts = $this->taxation->factsFor($tenantId, $productId, [$line], $issuedAt);

        $supplyType = $this->taxation->defaultSupplyType($productId);

        return $this->invoices->issue(
            $tenantId,
            $productId,
            $subscription->id,
            [$line],
            $supplier,
            $profile->snapshot(),
            SupplierIdentity::jurisdictionOf($supplier),
            $subscription->currentPeriodStart,
            $subscription->currentPeriodEnd,
            self::PAYMENT_TERMS,
            $actorUserId,
            // Inside the invoice's own transaction, so the document and the
            // fiscal fact it produces commit together.
            function (Invoice $invoice) use (
                $tenantId,
                $productId,
                $supplyType,
                $issuedAt,
                $facts,
            ): void {
                $this->taxation->recordFor(
                    $tenantId,
                    $productId,
                    $invoice->id,
                    null,
                    $supplyType,
                    $issuedAt,
                    $facts,
                );
            },
        );
    }

    /**
     * Reconciles an invoice by hand — a bank transfer that arrived.
     *
     * It goes through `settle` rather than the ordinary move because §25
     * makes this as real a way to be paid as a card is, and the sale waiting
     * on the money must be released either way. A customer who pays by
     * transfer and gets nothing is the bug this shape exists to make
     * impossible.
     */
    public function markPaid(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): Invoice
    {
        $invoice = $this->show($tenantId, $productId, $invoiceId);

        InvoiceStatus::assertPermits($invoice->status, InvoiceStatus::PAID);

        return $this->invoices->settle($invoice, $this->paid, $actorUserId);
    }

    public function cancel(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): Invoice
    {
        return $this->move($tenantId, $productId, $invoiceId, InvoiceStatus::CANCELLED, $actorUserId);
    }

    private function move(
        string $tenantId,
        string $productId,
        string $invoiceId,
        string $status,
        ?string $actorUserId,
    ): Invoice {
        $invoice = $this->show($tenantId, $productId, $invoiceId);

        // The state machine decides, not the caller and not the database.
        // Paying a cancelled invoice would put money against a debt that no
        // longer exists; re-issuing an issued one would allocate a second
        // legal number for one document.
        InvoiceStatus::assertPermits($invoice->status, $status);

        return $this->invoices->transition($invoice, $status, $actorUserId);
    }
}
