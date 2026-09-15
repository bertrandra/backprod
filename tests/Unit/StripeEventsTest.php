<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Payment\Domain\ProviderEvent;
use App\Payment\Infrastructure\Stripe\StripeEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Stripe's events, as this platform reads them — from trimmed copies of
 * the real shapes in `tests/Fixtures/stripe/`.
 *
 * Every row of the adapter's table, and the two things the table encodes:
 * that anything else parses to null rather than to an error, and that the
 * payload keeps only what somebody vetted.
 */
#[CoversClass(StripeEvents::class)]
final class StripeEventsTest extends TestCase
{
    private const INTENT = 'pi_3Q2RxX0000000000000001';

    public function testASucceededIntentIsACollectedPaymentWithItsMethod(): void
    {
        $event = self::parse('payment_intent.succeeded');

        self::assertSame('evt_1Q2RxYZ0000000000000001', $event->id);
        self::assertSame(ProviderEvent::PAYMENT_SUCCEEDED, $event->type);
        self::assertSame(self::INTENT, $event->providerPaymentId);
        self::assertSame(4900, $event->amountMinorUnits);
        self::assertSame('2026-09-12T08:00:00+00:00', $event->occurredAt?->format(DATE_ATOM));
        self::assertNull($event->failureCode);
        self::assertNull($event->providerRefundId);
        // The kind of instrument, never the instrument.
        // The column's own vocabulary, not Stripe's word.
        self::assertSame('CARD', $event->method);
        self::assertSame('CARD', $event->payload['method'] ?? null);
    }

    public function testThePayloadKeepsOnlyWhatWasVetted(): void
    {
        $payload = self::parse('payment_intent.succeeded')->payload;

        // The fixture carries a brand, a last four and a fingerprint, the way
        // Stripe's events do. None of it reaches the column humans read.
        self::assertSame(
            ['id', 'type', 'payment_intent', 'method'],
            array_keys($payload),
        );
        self::assertStringNotContainsString('4242', json_encode($payload) ?: '');
    }

    public function testAFailedIntentCarriesTheIssuersWordAndStripesSentence(): void
    {
        $event = self::parse('payment_intent.payment_failed');

        self::assertSame(ProviderEvent::PAYMENT_FAILED, $event->type);
        self::assertSame(self::INTENT, $event->providerPaymentId);
        // decline_code over code: the more specific one is what an operator wants.
        self::assertSame('insufficient_funds', $event->failureCode);
        self::assertSame('Your card has insufficient funds.', $event->failureReason);
    }

    public function testACancelledIntentIsACancelledPayment(): void
    {
        self::assertSame(ProviderEvent::PAYMENT_CANCELLED, self::parse('payment_intent.canceled')->type);
    }

    public function testASettledRefundKeysOnTheIntentAndNamesTheRefund(): void
    {
        $event = self::parse('refund.updated.succeeded');

        self::assertSame(ProviderEvent::REFUND_SUCCEEDED, $event->type);
        self::assertSame(self::INTENT, $event->providerPaymentId);
        self::assertSame('re_3Q2RxX0000000000000001', $event->providerRefundId);
        self::assertSame(1900, $event->amountMinorUnits);
    }

    public function testARefundNotYetSettledIsNothingYet(): void
    {
        // Settlement is the webhook's word (ADR-022); a pending refund has
        // not said it.
        self::assertNull(StripeEvents::parse(self::fixture('refund.updated.pending'), false));
    }

    public function testADisputeIsAChargebackOnTheIntent(): void
    {
        $event = self::parse('charge.dispute.created');

        self::assertSame(ProviderEvent::CHARGEBACK_OPENED, $event->type);
        self::assertSame(self::INTENT, $event->providerPaymentId);
        self::assertSame(4900, $event->amountMinorUnits);
        self::assertSame('fraudulent', $event->payload['reason'] ?? null);
    }

    public function testAnEventThisPlatformDoesNotModelIsNullNotAnError(): void
    {
        // `charge.succeeded` accompanies every succeeded intent; answering it
        // with anything but 200 would have Stripe retry it forever.
        self::assertNull(StripeEvents::parse(self::fixture('charge.succeeded'), false));
        self::assertNull(StripeEvents::parse('{"id":"evt_x","type":"customer.created","livemode":false,"data":{"object":{"id":"cus_x"}}}', false));
    }

    public function testAnEventFromTheOtherModeIsNotApplied(): void
    {
        // A live event on a sandbox key, or the reverse, is a webhook endpoint
        // configured in the wrong Stripe mode.
        self::assertNull(StripeEvents::parse(self::fixture('payment_intent.succeeded.livemode'), false));
        self::assertNull(StripeEvents::parse(self::fixture('payment_intent.succeeded'), true));
        self::assertNotNull(StripeEvents::parse(self::fixture('payment_intent.succeeded.livemode'), true));
    }

    public function testGarbageIsNull(): void
    {
        self::assertNull(StripeEvents::parse('not json', false));
        self::assertNull(StripeEvents::parse('{"id":"evt_1","type":"payment_intent.succeeded","livemode":false}', false));
        self::assertNull(StripeEvents::parse('{"id":"evt_1","type":"refund.updated","livemode":false,"data":{"object":{"id":"re_1","status":"succeeded"}}}', false));
    }

    private static function parse(string $name): ProviderEvent
    {
        $event = StripeEvents::parse(self::fixture($name), false);

        self::assertNotNull($event);

        return $event;
    }

    private static function fixture(string $name): string
    {
        $body = file_get_contents(__DIR__ . '/../Fixtures/stripe/' . $name . '.json');

        self::assertIsString($body);

        return $body;
    }
}
