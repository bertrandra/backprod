<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Billing\Domain\Money;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentProvider;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Domain\ProviderPayment;
use App\Payment\Domain\ProviderRefund;
use App\Shared\Exceptions\UnauthenticatedException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A payment provider that is honestly not one.
 *
 * It exists so the whole path — start a payment, receive a signed webhook,
 * apply it exactly once — is exercised end to end without a real PSP, and so
 * that the first real adapter has a worked example of what the port expects.
 * It is named "stub" in configuration and in `payments.provider`, so a row it
 * produced can never be mistaken for a row a real provider produced.
 *
 * The signature scheme is deliberately the same shape a real one has:
 * HMAC-SHA256 over the raw bytes, compared in constant time. Everything else
 * about it is a simplification; that part is not, because it is the part a
 * real adapter must get right and the part these tests need to be about.
 */
final class StubPaymentProvider implements PaymentProvider
{
    public const NAME = 'stub';
    public const SIGNATURE_HEADER = 'X-Payment-Signature';

    public function __construct(private readonly string $signingSecret)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isSandbox(): bool
    {
        // Nothing here has ever moved money.
        return true;
    }

    public function authorize(Money $amount, string $reference): ProviderPayment
    {
        // A real adapter calls the provider here. This one mints a handle
        // that is derived from the reference, so a test can predict it and a
        // human reading the two systems side by side can line them up.
        return new ProviderPayment(
            'stub_pi_' . substr(hash('sha256', $reference), 0, 24),
            PaymentStatus::PENDING,
            // A real client secret would come from the provider and is never
            // stored; this one is here to prove the shape travels.
            'stub_secret_' . substr(hash('sha256', $reference . $amount->minorUnits), 0, 16),
            null,
        );
    }

    public function verify(string $rawBody, array $headers): void
    {
        $supplied = self::header($headers, self::SIGNATURE_HEADER);

        if ($supplied === null) {
            throw new UnauthenticatedException();
        }

        $expected = hash_hmac('sha256', $rawBody, $this->signingSecret);

        // Constant time, so a wrong signature cannot be found a byte at a
        // time by measuring how long the rejection takes.
        if (!hash_equals($expected, $supplied)) {
            throw new UnauthenticatedException();
        }
    }

    public function parse(string $rawBody): ?ProviderEvent
    {
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return null;
        }

        $id = self::text($decoded, 'id');
        $type = self::mapType(self::text($decoded, 'type'));
        $reference = self::text($decoded, 'payment_id');

        // An event this platform does not model is not an error. Answering
        // one with a 4xx would make a provider retry it until it gave up,
        // and bury the deliveries that matter under the ones that do not.
        if ($id === null || $type === null || $reference === null) {
            return null;
        }

        return new ProviderEvent(
            $id,
            $type,
            $reference,
            self::moment(self::text($decoded, 'occurred_at')),
            self::text($decoded, 'failure_code'),
            self::text($decoded, 'failure_reason'),
            self::integer($decoded, 'amount_minor_units'),
            self::text($decoded, 'refund_id'),
            // Only the fields this platform understands are kept. A raw body
            // may carry anything, and this ends up in a column humans read
            // while investigating money.
            array_filter([
                'id' => $id,
                'type' => $type,
                'payment_id' => $reference,
                'refund_id' => self::text($decoded, 'refund_id'),
            ], static fn (?string $value): bool => $value !== null),
        );
    }

    public function refund(Payment $payment, Money $amount, string $reason): ProviderRefund
    {
        return new ProviderRefund(
            'stub_re_' . substr(hash('sha256', $payment->providerPaymentId . $amount->minorUnits . $reason), 0, 24),
            'PENDING',
        );
    }

    /**
     * The signature a caller must send for these bytes. Test and developer
     * tooling only — it is the same computation as verification, which is
     * exactly why a real adapter has no equivalent: there, only the provider
     * can produce one.
     */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->signingSecret);
    }

    private static function mapType(?string $type): ?string
    {
        return match ($type) {
            'payment.authorized' => ProviderEvent::PAYMENT_AUTHORIZED,
            'payment.succeeded' => ProviderEvent::PAYMENT_SUCCEEDED,
            'payment.failed' => ProviderEvent::PAYMENT_FAILED,
            'payment.cancelled' => ProviderEvent::PAYMENT_CANCELLED,
            'refund.succeeded' => ProviderEvent::REFUND_SUCCEEDED,
            'chargeback.opened' => ProviderEvent::CHARGEBACK_OPENED,
            default => null,
        };
    }

    /**
     * @param array<array-key, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            // Cast because a numeric header name would arrive as an int key.
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function text(array $decoded, string $field): ?string
    {
        $value = $decoded[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function integer(array $decoded, string $field): ?int
    {
        $value = $decoded[$field] ?? null;

        return is_int($value) ? $value : null;
    }

    private static function moment(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value, new DateTimeZone('UTC'));

        // An unparseable timestamp becomes absent rather than now(): the
        // column exists to say when the provider thinks it happened, and
        // substituting our own clock would be a quiet lie in a record kept
        // for investigating money.
        return $moment === false ? null : $moment;
    }
}
