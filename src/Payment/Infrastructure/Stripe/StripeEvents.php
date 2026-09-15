<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Stripe;

use App\Payment\Domain\ProviderEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stripe's events, normalised into the six this platform models.
 *
 * `ProviderEvent.id` is Stripe's `evt_…`, stable across retries, which is
 * what `payment_events_delivered_once` needs. `providerPaymentId` is always
 * the PaymentIntent — `pi_…` — taken from wherever the event carries it, so
 * every delivery about one payment keys on the string `authorize()` recorded
 * (ADR-048).
 *
 * Everything not in the table below parses to **null**: `payment_intent.created`,
 * `charge.succeeded`, `payment_intent.processing`, `refund.created`,
 * `charge.refunded`, `charge.dispute.closed`, every `customer.*`, `invoice.*`
 * and `checkout.*`. Null is not an error — the port says so and ADR-022 says
 * why: a 4xx makes Stripe retry until it gives up, and buries the deliveries
 * that matter under the ones that do not.
 *
 * `PAYMENT_AUTHORIZED` is never emitted. It corresponds to
 * `amount_capturable_updated`, which only fires under manual capture, which
 * this adapter does not do. A SEPA debit in `processing` maps to nothing:
 * the payment stays PENDING, which is what it is.
 */
final class StripeEvents
{
    /**
     * @param bool $livemode the mode the configured key belongs to
     */
    public static function parse(string $rawBody, bool $livemode): ?ProviderEvent
    {
        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            return null;
        }

        $id = self::text($event, 'id');
        $type = self::text($event, 'type');
        $object = self::sub(self::sub($event, 'data'), 'object');

        if ($id === null || $type === null || $object === []) {
            return null;
        }

        // A live key with a test event, or the reverse, is a webhook endpoint
        // configured in the wrong Stripe mode. Applying test money to live
        // invoices is the worst version of that mistake, so it is not applied
        // — and it is logged upstream by the outcome, not swallowed.
        if (($event['livemode'] ?? null) !== $livemode) {
            return null;
        }

        $occurredAt = is_int($event['created'] ?? null)
            ? (new DateTimeImmutable('@' . $event['created']))->setTimezone(new DateTimeZone('UTC'))
            : null;

        return match ($type) {
            'payment_intent.succeeded' => self::event(
                $id,
                ProviderEvent::PAYMENT_SUCCEEDED,
                self::text($object, 'id'),
                $occurredAt,
                amount: self::integer($object, 'amount_received'),
                method: self::method($object),
            ),
            'payment_intent.payment_failed' => self::event(
                $id,
                ProviderEvent::PAYMENT_FAILED,
                self::text($object, 'id'),
                $occurredAt,
                failureCode: self::failureCode($object),
                failureReason: self::text(self::sub($object, 'last_payment_error'), 'message'),
            ),
            'payment_intent.canceled' => self::event(
                $id,
                ProviderEvent::PAYMENT_CANCELLED,
                self::text($object, 'id'),
                $occurredAt,
            ),
            'refund.updated' => self::text($object, 'status') === 'succeeded'
                ? self::event(
                    $id,
                    ProviderEvent::REFUND_SUCCEEDED,
                    self::text($object, 'payment_intent'),
                    $occurredAt,
                    amount: self::integer($object, 'amount'),
                    refundId: self::text($object, 'id'),
                )
                : null,
            'charge.dispute.created' => self::event(
                $id,
                ProviderEvent::CHARGEBACK_OPENED,
                self::text($object, 'payment_intent'),
                $occurredAt,
                amount: self::integer($object, 'amount'),
                extra: ['reason' => self::text($object, 'reason')],
            ),
            default => null,
        };
    }

    /**
     * @param array<string, string|null> $extra
     */
    private static function event(
        string $id,
        string $type,
        ?string $paymentIntent,
        ?DateTimeImmutable $occurredAt,
        ?string $failureCode = null,
        ?string $failureReason = null,
        ?int $amount = null,
        ?string $refundId = null,
        ?string $method = null,
        array $extra = [],
    ): ?ProviderEvent {
        // An event about no PaymentIntent — a dispute on a charge made outside
        // this platform, say — is about nothing this platform records.
        if ($paymentIntent === null) {
            return null;
        }

        // Only the fields this platform understands are kept. A raw Stripe
        // event is kilobytes of things nobody vetted, and `payment_events.payload`
        // is a column humans read while investigating money.
        $payload = array_filter(
            ['id' => $id, 'type' => $type, 'payment_intent' => $paymentIntent, 'refund' => $refundId, 'method' => $method] + $extra,
            static fn (?string $value): bool => $value !== null,
        );

        return new ProviderEvent(
            $id,
            $type,
            $paymentIntent,
            $occurredAt,
            $failureCode,
            $failureReason,
            $amount,
            $refundId,
            $payload,
            $method,
        );
    }

    /**
     * The kind of instrument — `card`, `sepa_debit`, `link` — and never the
     * instrument: no PAN, no last four, no fingerprint (§24).
     *
     * @param array<mixed, mixed> $intent
     */
    private static function method(array $intent): ?string
    {
        $details = self::sub(self::sub($intent, 'latest_charge'), 'payment_method_details');
        $kind = self::text($details, 'type');

        if ($kind === null) {
            $types = $intent['payment_method_types'] ?? null;

            // Unexpanded: the types the intent allowed, useful only when one.
            $kind = is_array($types) && count($types) === 1 && is_string($types[0]) ? $types[0] : null;
        }

        return $kind === null ? null : self::label($kind, self::sub(self::sub($details, 'card'), 'wallet'));
    }

    /**
     * Stripe's method type onto the label `payments.method` accepts — its
     * own vocabulary, not the provider's, so a second PSP's words never
     * reach the column. A wallet is a card underneath; Stripe says which in
     * `card.wallet.type`.
     *
     * @param array<mixed, mixed> $wallet
     */
    private static function label(string $kind, array $wallet): string
    {
        $walletType = self::text($wallet, 'type');

        return match (true) {
            $kind === 'card' && $walletType === 'apple_pay' => 'APPLE_PAY',
            $kind === 'card' && $walletType === 'google_pay' => 'GOOGLE_PAY',
            $kind === 'card' => 'CARD',
            $kind === 'sepa_debit' => 'SEPA_DEBIT',
            $kind === 'customer_balance', $kind === 'bank_transfer' => 'TRANSFER',
            default => 'OTHER',
        };
    }

    /**
     * @param array<mixed, mixed> $intent
     */
    private static function failureCode(array $intent): ?string
    {
        $error = self::sub($intent, 'last_payment_error');

        if ($error === []) {
            return null;
        }

        // `decline_code` is the issuer's word (`insufficient_funds`), `code`
        // Stripe's (`card_declined`); the more specific one is the one an
        // operator wants to read.
        return self::text($error, 'decline_code') ?? self::text($error, 'code');
    }

    /**
     * A nested object, or an empty array when absent or not an object — so a
     * chain of lookups needs no branch per level.
     *
     * @param array<mixed, mixed> $source
     *
     * @return array<mixed, mixed>
     */
    private static function sub(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed, mixed> $source
     */
    private static function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<mixed, mixed> $source
     */
    private static function integer(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
