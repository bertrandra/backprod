<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\Money;
use App\Payment\Domain\ProviderEvent;
use App\Payment\Infrastructure\StubPaymentProvider;
use App\Shared\Exceptions\UnauthenticatedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The adapter's two jobs: proving a delivery came from the provider, and
 * turning it into terms this platform uses.
 *
 * The verification tests are the ones that matter. This is the only
 * unauthenticated write endpoint in the platform, so a signature check that
 * can be got past is a stranger moving money.
 */
#[CoversClass(StubPaymentProvider::class)]
final class StubPaymentProviderTest extends TestCase
{
    private const SECRET = 'a-signing-secret';

    public function testAGenuineSignaturePasses(): void
    {
        $provider = self::provider();
        $body = self::body();

        $provider->verify($body, [StubPaymentProvider::SIGNATURE_HEADER => $provider->sign($body)]);

        // verify() returns nothing and throws on failure, so reaching here
        // is the assertion.
        $this->addToAssertionCount(1);
    }

    public function testHeaderMatchingIsCaseInsensitive(): void
    {
        $provider = self::provider();
        $body = self::body();

        // PSR-7 preserves the case a client sent, and providers are not
        // consistent about it. Matching exactly would reject genuine
        // deliveries.
        $provider->verify($body, ['x-payment-signature' => $provider->sign($body)]);

        $this->addToAssertionCount(1);
    }

    public function testAMissingSignatureIsRefused(): void
    {
        $this->expectException(UnauthenticatedException::class);

        self::provider()->verify(self::body(), []);
    }

    public function testAWrongSignatureIsRefused(): void
    {
        $this->expectException(UnauthenticatedException::class);

        self::provider()->verify(self::body(), [
            StubPaymentProvider::SIGNATURE_HEADER => str_repeat('0', 64),
        ]);
    }

    public function testASignatureFromADifferentSecretIsRefused(): void
    {
        $forged = new StubPaymentProvider('not-the-secret');
        $body = self::body();

        $this->expectException(UnauthenticatedException::class);

        self::provider()->verify($body, [
            StubPaymentProvider::SIGNATURE_HEADER => $forged->sign($body),
        ]);
    }

    public function testATamperedBodyIsRefused(): void
    {
        $provider = self::provider();
        $signature = $provider->sign(self::body());

        // The attack this exists to stop: take a genuine delivery, change
        // which payment it is about, keep the signature.
        $tampered = self::body(['payment_id' => 'stub_pi_somebody_elses']);

        $this->expectException(UnauthenticatedException::class);

        $provider->verify($tampered, [StubPaymentProvider::SIGNATURE_HEADER => $signature]);
    }

    public function testAVerifiedDeliveryIsNormalised(): void
    {
        $event = self::provider()->parse(self::body([
            'type' => 'payment.succeeded',
            'occurred_at' => '2026-09-03T04:00:00+00:00',
        ]));

        self::assertNotNull($event);
        self::assertSame('evt_1', $event->id);
        self::assertSame(ProviderEvent::PAYMENT_SUCCEEDED, $event->type);
        self::assertSame('stub_pi_abc', $event->providerPaymentId);
        self::assertNotNull($event->occurredAt);
        self::assertSame('2026-09-03T04:00:00+00:00', $event->occurredAt->format(DATE_RFC3339));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function eventTypes(): array
    {
        return [
            ['payment.authorized', ProviderEvent::PAYMENT_AUTHORIZED],
            ['payment.succeeded', ProviderEvent::PAYMENT_SUCCEEDED],
            ['payment.failed', ProviderEvent::PAYMENT_FAILED],
            ['payment.cancelled', ProviderEvent::PAYMENT_CANCELLED],
            ['refund.succeeded', ProviderEvent::REFUND_SUCCEEDED],
            ['chargeback.opened', ProviderEvent::CHARGEBACK_OPENED],
        ];
    }

    #[DataProvider('eventTypes')]
    public function testEveryModelledTypeIsMapped(string $providerType, string $expected): void
    {
        $event = self::provider()->parse(self::body(['type' => $providerType]));

        self::assertNotNull($event);
        self::assertSame($expected, $event->type);
    }

    /**
     * @return list<array{string}>
     */
    public static function unmodelledBodies(): array
    {
        return [
            // A type this platform has no opinion about.
            ['{"id":"evt_1","type":"invoice.dreamed","payment_id":"stub_pi_abc"}'],
            // Missing the handle that makes it resolvable.
            ['{"id":"evt_1","type":"payment.succeeded"}'],
            // Missing the id that makes replay detectable.
            ['{"type":"payment.succeeded","payment_id":"stub_pi_abc"}'],
            ['not json at all'],
            ['[]'],
            ['null'],
        ];
    }

    #[DataProvider('unmodelledBodies')]
    public function testADeliveryThisPlatformDoesNotModelParsesToNothing(string $body): void
    {
        // Null rather than an exception, because the endpoint answers these
        // with a 2xx. A provider retries anything else, and an event nobody
        // will ever handle would be retried until it gave up — burying the
        // deliveries that matter.
        self::assertNull(self::provider()->parse($body));
    }

    public function testAnUnparseableTimestampBecomesAbsentRatherThanNow(): void
    {
        $event = self::provider()->parse(self::body(['occurred_at' => 'yesterday-ish']));

        self::assertNotNull($event);
        // Substituting our own clock would be a quiet lie in a record kept
        // for investigating money.
        self::assertNull($event->occurredAt);
    }

    public function testTheKeptPayloadCarriesNothingUnvetted(): void
    {
        $event = self::provider()->parse(self::body([
            'card_number' => '4242424242424242',
            'customer_note' => 'anything at all',
        ]));

        self::assertNotNull($event);
        // Only fields the adapter understands survive. A raw body may carry
        // anything, and this ends up in a column humans read.
        self::assertSame(['id', 'type', 'payment_id'], array_keys($event->payload));
    }

    public function testAHandleIsDerivedFromTheReferenceSoTheTwoSystemsLineUp(): void
    {
        $provider = self::provider();

        $first = $provider->authorize(Money::of(3480, 'EUR'), '2026-000001');
        $again = $provider->authorize(Money::of(3480, 'EUR'), '2026-000001');

        self::assertSame($first->id, $again->id);
        self::assertNotSame(
            $first->id,
            $provider->authorize(Money::of(3480, 'EUR'), '2026-000002')->id,
        );
    }

    private static function provider(): StubPaymentProvider
    {
        return new StubPaymentProvider(self::SECRET);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private static function body(array $changes = []): string
    {
        return json_encode(array_merge([
            'id' => 'evt_1',
            'type' => 'payment.succeeded',
            'payment_id' => 'stub_pi_abc',
        ], $changes), JSON_THROW_ON_ERROR);
    }
}
