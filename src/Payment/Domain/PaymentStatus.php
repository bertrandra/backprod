<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * What has happened to a payment, and which moves are possible.
 *
 * This is the rule that makes a delayed webhook harmless. Providers deliver
 * out of order — a retry of `payment.failed` can arrive after the
 * `payment.succeeded` that superseded it — and applying whatever turns up
 * last would reverse a collected payment on the strength of a stale message.
 *
 * So the machine is one-way. Nothing leads back out of a terminal state, and
 * an event asking for a move that is not permitted is recorded and ignored
 * rather than applied.
 */
final class PaymentStatus
{
    public const PENDING = 'PENDING';
    public const AUTHORIZED = 'AUTHORIZED';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';
    public const REFUNDED = 'REFUNDED';
    public const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const CHARGEBACK = 'CHARGEBACK';

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        self::PENDING => [self::AUTHORIZED, self::SUCCEEDED, self::FAILED, self::CANCELLED],
        self::AUTHORIZED => [self::SUCCEEDED, self::FAILED, self::CANCELLED],
        // Money arrived. It can only leave again, and only in ways that are
        // themselves recorded as refunds.
        self::SUCCEEDED => [self::REFUNDED, self::PARTIALLY_REFUNDED, self::CHARGEBACK],
        self::PARTIALLY_REFUNDED => [self::REFUNDED, self::CHARGEBACK],
        // A failed payment is not retried into life: a retry is a new
        // attempt with its own provider reference, because the customer may
        // have used a different instrument and the two must be told apart.
        self::FAILED => [],
        self::CANCELLED => [],
        self::REFUNDED => [],
        self::CHARGEBACK => [],
    ];

    public static function permits(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /**
     * Whether money is currently held for this payment.
     *
     * Partially refunded counts: some of it is still ours, and an invoice
     * settled by a partly-refunded payment is still settled.
     */
    public static function isSettled(string $status): bool
    {
        return $status === self::SUCCEEDED || $status === self::PARTIALLY_REFUNDED;
    }

    public static function isFinal(string $status): bool
    {
        return (self::ALLOWED[$status] ?? []) === [];
    }
}
