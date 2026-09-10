<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Refund;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;

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
    ) {
    }

    /**
     * @return array{payments: list<Payment>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'payments' => $this->payments->listForTenant($tenantId, $productId, $limit, $offset),
            'total' => $this->payments->countForTenant($tenantId, $productId),
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

    public function show(string $tenantId, string $productId, string $paymentId): Payment
    {
        $payment = $this->payments->find($tenantId, $productId, $paymentId);

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
     * @return array{payment: Payment, client_secret: string|null}
     */
    public function start(string $tenantId, string $productId, string $invoiceId, ?string $actorUserId): array
    {
        $invoice = $this->requireInvoice($tenantId, $productId, $invoiceId);

        if ($invoice->status !== InvoiceStatus::ISSUED) {
            // A draft has not been sent, a paid one is settled, and a
            // cancelled one is a debt that no longer exists. None of the
            // three should be collectable.
            throw new ConflictException(
                'INVOICE_NOT_PAYABLE',
                'Only an issued invoice can be paid.',
                ['status' => $invoice->status],
            );
        }

        $provider = $this->providers->default();
        $started = $provider->authorize($invoice->gross, $invoice->number ?? $invoice->id);

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
        // database.
        return ['payment' => $payment, 'client_secret' => $started->clientSecret];
    }

    /**
     * Asks the provider to return money.
     *
     * The refund is recorded as PENDING and settles when the provider says
     * so, through the same webhook everything else arrives by. Marking it
     * settled here would be this platform telling itself money moved.
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

        $amount = $amountMinorUnits === null
            ? $payment->amount
            : Money::of($amountMinorUnits, $payment->amount->currency);

        $this->assertRefundable($payment, $amount);

        $provider = $this->providers->named($payment->provider);

        return $this->payments->recordRefund(
            $payment,
            $provider->refund($payment, $amount, $reason),
            $amount,
            $reason,
            $actorUserId,
        );
    }

    /**
     * Refunding more than was collected is refused here rather than left to
     * the provider, because the provider's refusal arrives as a failed API
     * call that somebody has to interpret, and the answer "you cannot give
     * back more than you took" does not need a round trip.
     */
    private function assertRefundable(Payment $payment, Money $amount): void
    {
        if ($amount->minorUnits <= 0) {
            throw new ConflictException('REFUND_AMOUNT_INVALID', 'A refund must be for a positive amount.');
        }

        $alreadyReturned = 0;

        foreach ($this->payments->refundsOf($payment->id) as $refund) {
            if ($refund->status !== Refund::FAILED) {
                $alreadyReturned += $refund->amount->minorUnits;
            }
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

    private function requireInvoice(string $tenantId, string $productId, string $invoiceId): Invoice
    {
        $invoice = $this->invoices->find($tenantId, $productId, $invoiceId);

        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.', [], 'INVOICE_NOT_FOUND');
        }

        return $invoice;
    }
}
