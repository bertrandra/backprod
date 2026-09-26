<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Billing\Domain\Money;
use App\Payment\Domain\Collected;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Refund;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One shape for payments and refunds.
 *
 * `provider_payment_id` is included because reconciling this platform
 * against a PSP dashboard is a thing finance teams actually do, and hiding
 * the handle makes it a support ticket. It is not a secret: it identifies a
 * payment to somebody who already has the payment.
 *
 * What is never here is an instrument. `method` is a label — "CARD" — and
 * there is nothing else to leak, because §24 keeps it all outside.
 */
final class PaymentPresenter
{
    /**
     * What the page needs to use a `client_secret`: the provider, its
     * page-side key, and whether this is a sandbox — so the screen can say
     * "no money moves" rather than let a person find out.
     *
     * @param array{name: string, sandbox: bool, client_key: string|null}|null $provider
     *
     * @return array<string, mixed>|null
     */
    public static function provider(?array $provider): ?array
    {
        return $provider === null
            ? null
            : ['name' => $provider['name'], 'publishable_key' => $provider['client_key'], 'sandbox' => $provider['sandbox']];
    }

    /**
     * @param Collected|null $collected what the payment collects, and who that
     *                                  document names (2026-09-26) — null
     *                                  where the invoice has since been
     *                                  removed, which nothing in this platform
     *                                  does but which a read must survive
     *
     * @return array<string, mixed>
     */
    public static function one(Payment $payment, ?Collected $collected = null): array
    {
        return [
            'id' => $payment->id,
            'invoice_id' => $payment->invoiceId,
            // The document and its customer, beside the attempt to collect it
            // (2026-09-26). An administrator sees every payment the
            // organisation has, and without these a list of twelve identical
            // amounts was a list of twelve identical rows.
            //
            // Null rather than absent, and null rather than a placeholder: an
            // invoice still in draft has no number, and inventing one is how a
            // hole enters a sequence that must not have one.
            'invoice_number' => $collected?->number,
            'customer_name' => $collected?->customerName,
            'customer_email' => $collected?->customerEmail,
            'subscription_id' => $payment->subscriptionId,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->providerPaymentId,
            'status' => $payment->status,
            'settled' => $payment->isSettled(),
            'final' => PaymentStatus::isFinal($payment->status),
            'amount' => self::money($payment->amount),
            'method' => $payment->method,
            'failure_code' => $payment->failureCode,
            'failure_reason' => $payment->failureReason,
            'succeeded_at' => self::nullableMoment($payment->succeededAt),
            'failed_at' => self::nullableMoment($payment->failedAt),
            'created_at' => self::moment($payment->createdAt),
        ];
    }

    /**
     * @param list<Payment>            $payments
     * @param array<string, Collected> $collected by invoice id, for the whole
     *                                            page at once — one extra read
     *                                            for twenty-five rows, never
     *                                            twenty-five
     *
     * @return list<array<string, mixed>>
     */
    public static function many(array $payments, array $collected = []): array
    {
        return array_map(
            static fn (Payment $payment): array => self::one($payment, $collected[$payment->invoiceId] ?? null),
            $payments,
        );
    }

    /**
     * The invoice ids a page of payments collects, for one read of them.
     *
     * @param list<Payment> $payments
     *
     * @return list<string>
     */
    public static function invoicesOf(array $payments): array
    {
        return array_values(array_unique(array_map(
            static fn (Payment $payment): string => $payment->invoiceId,
            $payments,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public static function refund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'payment_id' => $refund->paymentId,
            'amount' => self::money($refund->amount),
            'reason' => $refund->reason,
            // Surfaced rather than left to be inferred from the reason: a
            // chargeback is a dispute, and a client showing it as an
            // ordinary refund tells the customer the wrong story.
            'disputed' => $refund->isDisputed(),
            'status' => $refund->status,
            'settled_at' => self::nullableMoment($refund->settledAt),
            'created_at' => self::moment($refund->createdAt),
        ];
    }

    /**
     * @param list<Refund> $refunds
     *
     * @return list<array<string, mixed>>
     */
    public static function refunds(array $refunds): array
    {
        return array_map(self::refund(...), $refunds);
    }

    /**
     * @return array{minor_units: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['minor_units' => $money->minorUnits, 'currency' => $money->currency];
    }

    private static function moment(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339);
    }

    private static function nullableMoment(?DateTimeImmutable $moment): ?string
    {
        return $moment === null ? null : self::moment($moment);
    }
}
