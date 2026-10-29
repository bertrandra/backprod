<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\DocumentPeople;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Service\CreditNotes;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Refund;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Money\Money;

/**
 * Starting payments, reading them, and asking for money back.
 *
 * What this deliberately cannot do is declare a payment successful. Nothing
 * here sets SUCCEEDED, because §24 makes the provider's webhook the source of
 * truth and a platform that could mark its own payments collected would be
 * trusting the browser that redirected back.
 */
final class Payments
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentProviders $providers,
        private readonly CreditNotes $creditNotes,
        private readonly DocumentPeople $people,
    ) {
    }

    /**
     * A page of them — the caller's own when `$ownedBy` is set, one person's
     * when `$person` is, inside the window `$from`..`$to` — and whom each
     * concerns (2026-09-27, the window 2026-09-28), so the list can name the
     * person beside every document.
     *
     * @return array{payments: list<Payment>, people: array<string, array{user_id: string, name: string|null, email: string|null}>, total: int, limit: int, offset: int}
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
        $page = $this->payments->listForTenant($tenantId, $productId, $limit, $offset, $ownedBy, $person, $status, $from, $to);

        return [
            'payments' => $page,
            'people' => $this->people->ofPayments($tenantId, array_map(static fn ($document): string => $document->id, $page)),
            'total' => $this->payments->countForTenant($tenantId, $productId, $ownedBy, $person, $status, $from, $to),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * The latest attempt against an invoice, or null if nothing was ever
     * started. Not an error: an invoice with nothing collected yet is an
     * ordinary state, not a missing thing.
     */
    public function latestFor(string $tenantId, string $productId, string $invoiceId): ?Payment
    {
        return $this->payments->latestForInvoice($tenantId, $productId, $invoiceId);
    }

    public function show(string $tenantId, string $productId, string $paymentId, ?string $ownedBy = null): Payment
    {
        $payment = $this->payments->find($tenantId, $productId, $paymentId, $ownedBy);

        if ($payment === null) {
            throw new NotFoundException('Payment not found.', [], 'PAYMENT_NOT_FOUND');
        }

        return $payment;
    }

    /**
     * @return list<Refund>
     */
    public function refundsOf(Payment $payment): array
    {
        return $this->payments->refundsOf($payment->id);
    }

    /**
     * Starts collecting an invoice.
     *
     * The amount is the invoice's, never the caller's: a client that could
     * name a figure could name a smaller one. What comes back carries the
     * provider's handle for completing the payment, which is the only part
     * the front end needs and the only part this platform does not store.
     *
     * @return array{payment: Payment, client_secret: string|null, provider: array{name: string, sandbox: bool, client_key: string|null}}
     */
    public function start(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): array
    {
        $invoice = $this->requireInvoice($tenantId, $productId, $invoiceId);

        if (!InvoiceStatus::isPayable($invoice->status)) {
            // A draft has not been sent, a paid one is settled, and a
            // cancelled or credited one is a debt that no longer exists. None
            // of them is collectable. An invoice on its way through
            // e-invoicing still is (2026-10-29): transmitting a document does
            // not settle it, and refusing here left it owed with no way to pay.
            throw new ConflictException(
                'INVOICE_NOT_PAYABLE',
                'Only an issued, unpaid invoice can be paid.',
                ['status' => $invoice->status],
            );
        }

        $this->supersedeLiveAttempts($invoice, $actorUserId);

        $provider = $this->providers->default();

        // The reference names the attempt, not the invoice. Handing the same
        // reference over twice would ask the provider to authorize the same
        // thing again — an idempotent provider would hand back the intent
        // that already failed, and the unique index on (provider,
        // provider_payment_id) would refuse the row outright.
        $attempt = $this->payments->attemptsForInvoice($invoice->id) + 1;
        $reference = sprintf('%s/%d', $invoice->number ?? $invoice->id, $attempt);

        // The reference is for people; the key is for the provider's memory
        // (2026-09-18): the invoice's row id never repeats, its number does.
        $started = $provider->authorize($invoice->gross, $reference, sprintf('%s/%d', $invoice->id, $attempt));

        $payment = $this->payments->start(
            $tenantId,
            $productId,
            $invoice->id,
            $invoice->subscriptionId,
            $provider->name(),
            $started,
            $invoice->gross,
            $actorUserId,
        );

        // The secret is handed back and never stored: it is short-lived, it
        // is a credential of sorts, and §31 keeps credentials out of the
        // database. Beside it, what a page needs to *use* one: which
        // provider, the key that loads its component, and whether any of
        // this moves real money (ADR-048).
        return [
            'payment' => $payment,
            'client_secret' => $started->clientSecret,
            'provider' => [
                'name' => $provider->name(),
                'sandbox' => $provider->isSandbox(),
                'client_key' => $provider->clientKey(),
            ],
        ];
    }

    /**
     * One attempt at a time per invoice (2026-10-29).
     *
     * Every call minted a new attempt beside whatever was still live, so a
     * customer with two tabs — or a dunning step beside the page they had
     * left open — could authorize one invoice twice and be collected twice.
     * `payments_one_live_attempt_per_invoice` now refuses a second live
     * attempt outright.
     *
     * Refusing alone would be worse than the bug: an attempt nobody completes
     * stays PENDING for ever, so an abandoned page — or the dunning pass's own
     * attempt, which nobody but the customer can complete — would leave the
     * invoice unpayable. So the attempt still live is **abandoned at the
     * provider first**, and only an attempt the provider confirms can no
     * longer collect is replaced. One the provider says is past that point —
     * a SEPA debit in flight, a card already charged — refuses the new one,
     * because that money is coming.
     */
    private function supersedeLiveAttempts(Invoice $invoice, ?string $actorUserId): void
    {
        foreach ($this->payments->liveAttemptsForInvoice($invoice->id) as $live) {
            $abandoned = $this->providers->named($live->provider)->cancel($live)
                && $this->payments->supersede($live, $actorUserId);

            if (!$abandoned) {
                throw new ConflictException(
                    'PAYMENT_STILL_IN_FLIGHT',
                    'A payment of this invoice is still in progress. Wait for it to settle or fail.',
                    ['payment_id' => $live->id, 'status' => $live->status],
                );
            }
        }
    }

    /**
     * Asks the provider to return money, and raises the credit note that
     * makes it legal.
     *
     * The refund is recorded with the provider's own answer — usually
     * PENDING, settled later by the same webhook everything else arrives by;
     * SUCCEEDED when the provider settled it on the spot; FAILED when it
     * refused, in which case no document is written. Marking it settled here
     * on our own say-so would be this platform telling itself money moved.
     *
     * **The document is not conditional on that settlement**, and the order
     * of the steps below is the whole of the reasoning:
     *
     * ```text
     * lock the invoice       every other refund and credit note waits
     * plan the credit note   refusable, and costs nothing when refused
     * ask the provider       cannot be taken back
     * write both, together   one transaction, or neither
     * ```
     *
     * Planning first is what makes a refusal — a multi-rate invoice, an
     * invoice already credited — a refusal rather than money gone with
     * nothing to declare it. The lock is what keeps the plan true until it is
     * written (2026-10-29): the ceilings used to be read with nothing held,
     * so a concurrent credit could land between the plan and the write, and
     * the write then refused `CREDIT_EXCEEDS_INVOICE` after the money had
     * left. Writing both together is the rule the early-termination charge
     * follows for the same reason: a release with the buy-out unbilled is
     * revenue given away, and a refund with no credit note is VAT declared on
     * a sale that was undone (ADR-057).
     *
     * A chargeback is deliberately not this. It arrives as a webhook, it is
     * imposed rather than granted, and §25 says correcting the document is a
     * decision somebody makes.
     */
    public function refund(
        string $tenantId,
        string $productId,
        string $paymentId,
        ?int $amountMinorUnits,
        string $reason,
        ?string $actorUserId,
    ): Refund {
        $payment = $this->show($tenantId, $productId, $paymentId);

        if (!PaymentStatus::isSettled($payment->status)) {
            throw new ConflictException(
                'PAYMENT_NOT_REFUNDABLE',
                'Only a payment that collected money can be refunded.',
                ['status' => $payment->status],
            );
        }

        $provider = $this->providers->named($payment->provider);

        return $this->payments->recordRefund(
            $payment,
            $reason,
            $actorUserId,
            function () use ($payment, $amountMinorUnits, $reason, $actorUserId, $provider, $tenantId, $productId): array {
                // Read under the invoice's lock, so neither ceiling can move
                // before the write.
                $refunds = $this->payments->refundsOf($payment->id);
                $returned = self::returned($refunds);

                // Absent means everything still refundable, as the contract
                // says (2026-10-29) — not the whole payment, which after a
                // partial refund is more than is left.
                $amount = Money::of(
                    $amountMinorUnits ?? $payment->amount->minorUnits - $returned,
                    $payment->amount->currency,
                );

                if ($amountMinorUnits === null && $amount->minorUnits <= 0) {
                    throw new ConflictException(
                        'PAYMENT_NOT_REFUNDABLE',
                        'Everything this payment collected has already been given back, or is on its way back.',
                        ['collected' => $payment->amount->minorUnits, 'already_returned' => $returned],
                    );
                }

                self::assertRefundable($payment, $amount, $returned);

                // Before the provider is asked, because the provider cannot be
                // unasked — and read again here, under the lock, so the plan
                // is made against the invoice as it stands.
                $invoice = $this->requireInvoice($tenantId, $productId, $payment->invoiceId);
                $plan = $this->creditNotes->planFor($invoice, $amount);

                return [
                    // The ordinal tells two identical partial refunds apart at
                    // a provider that remembers requests (2026-10-29).
                    'refund' => $provider->refund($payment, $amount, $reason, count($refunds) + 1),
                    'amount' => $amount,
                    'alsoRecord' => fn (Refund $refund): string => $this->creditNotes->applyPlan(
                        $plan,
                        sprintf('Refund of payment (%s).', strtolower($reason)),
                        $actorUserId,
                    )->id,
                ];
            },
        );
    }

    /**
     * Refunding more than was collected is refused here rather than left to
     * the provider, because the provider's refusal arrives as a failed API
     * call that somebody has to interpret, and the answer "you cannot give
     * back more than you took" does not need a round trip.
     */
    private static function assertRefundable(Payment $payment, Money $amount, int $alreadyReturned): void
    {
        if ($amount->minorUnits <= 0) {
            throw new ConflictException('REFUND_AMOUNT_INVALID', 'A refund must be for a positive amount.');
        }

        if ($alreadyReturned + $amount->minorUnits > $payment->amount->minorUnits) {
            throw new ConflictException(
                'REFUND_EXCEEDS_PAYMENT',
                'That would return more than was collected.',
                [
                    'collected' => $payment->amount->minorUnits,
                    'already_returned' => $alreadyReturned,
                    'requested' => $amount->minorUnits,
                ],
            );
        }
    }

    /**
     * What has gone back, or is on its way: every refund but a failed one. A
     * failed refund is not money returned — the provider refused it — so it
     * does not bound the next one.
     *
     * @param list<Refund> $refunds
     */
    private static function returned(array $refunds): int
    {
        $total = 0;

        foreach ($refunds as $refund) {
            if ($refund->status !== Refund::FAILED) {
                $total += $refund->amount->minorUnits;
            }
        }

        return $total;
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
