<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Domain\BillingProfile;
use App\Billing\Domain\BillingProfileRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Commerce\Domain\Subscription;
use App\Commerce\Service\Subscriptions;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;

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
        private readonly BillingProfileRepository $profiles,
        private readonly Subscriptions $subscriptions,
        private readonly VatPolicy $vat,
        private readonly SupplierIdentity $supplier,
    ) {
    }

    /**
     * @return array{invoices: list<Invoice>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'invoices' => $this->invoices->listForTenant($tenantId, $productId, $limit, $offset),
            'total' => $this->invoices->countForTenant($tenantId, $productId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $invoiceId): Invoice
    {
        $invoice = $this->invoices->find($tenantId, $productId, $invoiceId);

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

        $line = InvoiceLine::of(
            1,
            self::describe($subscription),
            1,
            Money::of($version->priceMinorUnits, $version->currency),
            Money::zero($version->currency),
            $this->vat->rateFor($productId, $profile->countryCode),
            $version->id,
        );

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
        );
    }

    public function markPaid(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): Invoice
    {
        return $this->move($tenantId, $productId, $invoiceId, InvoiceStatus::PAID, $actorUserId);
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

    /**
     * What the line says.
     *
     * The offer's name and version, never its plan's code: §13 forbids
     * behaviour keyed on a plan, and a description that read "Pro plan"
     * would be the first place a report started grepping for one. The
     * version number is included because two invoices at different prices
     * for the same offer are otherwise indistinguishable to a customer
     * asking why the amount changed.
     */
    private static function describe(Subscription $subscription): string
    {
        return sprintf(
            '%s (v%d) — subscription',
            $subscription->offer->name,
            $subscription->offer->version->version,
        );
    }
}
