<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use DateTimeImmutable;

/**
 * A webhook delivery, normalised by the adapter into terms this platform
 * uses.
 *
 * The point of normalising is non-negotiable #17: nothing above the adapter
 * knows what the provider called its event, so a second provider is a second
 * adapter rather than a branch in the handler.
 *
 * `id` is the provider's identifier for the delivery, and it is what makes
 * replay detectable. A provider that did not supply one would have to have
 * its adapter derive a stable one from the payload — never a random value,
 * which would make every retry look new.
 *
 * `payload` is what the adapter chose to keep, not the raw body. Raw bodies
 * carry fields nobody has vetted, and this ends up in a column that humans
 * read while investigating money.
 */
final class ProviderEvent
{
    public const PAYMENT_AUTHORIZED = 'PAYMENT_AUTHORIZED';
    public const PAYMENT_SUCCEEDED = 'PAYMENT_SUCCEEDED';
    public const PAYMENT_FAILED = 'PAYMENT_FAILED';
    public const PAYMENT_CANCELLED = 'PAYMENT_CANCELLED';
    public const REFUND_SUCCEEDED = 'REFUND_SUCCEEDED';
    public const CHARGEBACK_OPENED = 'CHARGEBACK_OPENED';

    /**
     * The provider has said what kind of instrument paid — and nothing
     * else. It moves no status, appends no ledger row and settles nothing;
     * it fills `payments.method` when that column is still empty. A
     * provider whose customer chooses the instrument after the payment is
     * started (ADR-048) reports the outcome and the instrument in two
     * deliveries, in no guaranteed order, so this one is applied whenever
     * it lands and is harmless in either position.
     */
    public const INSTRUMENT_KNOWN = 'INSTRUMENT_KNOWN';

    /**
     * @param array<string, mixed> $payload
     * @param ?string              $method  the kind of instrument — `CARD`,
     *                                      `SEPA_DEBIT` — in `payments.method`'s
     *                                      own vocabulary, when the event is
     *                                      the first to know it. A provider
     *                                      that lets the customer choose after
     *                                      the payment is started (ADR-048)
     *                                      cannot say at `authorize()` time;
     *                                      never the instrument itself (§24)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $providerPaymentId,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly ?string $failureCode,
        public readonly ?string $failureReason,
        public readonly ?int $amountMinorUnits,
        public readonly ?string $providerRefundId,
        public readonly array $payload,
        public readonly ?string $method = null,
    ) {
    }

    /**
     * The payment status this event is asking for, or null when the event
     * does not move the payment itself.
     */
    public function requestedStatus(): ?string
    {
        return match ($this->type) {
            self::PAYMENT_AUTHORIZED => PaymentStatus::AUTHORIZED,
            self::PAYMENT_SUCCEEDED => PaymentStatus::SUCCEEDED,
            self::PAYMENT_FAILED => PaymentStatus::FAILED,
            self::PAYMENT_CANCELLED => PaymentStatus::CANCELLED,
            self::CHARGEBACK_OPENED => PaymentStatus::CHARGEBACK,
            default => null,
        };
    }
}
