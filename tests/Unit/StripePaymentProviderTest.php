<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Billing\Domain\Money;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\Stripe\StripePaymentProvider;
use App\Shared\Exceptions\NotConfiguredException;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Shared\Exceptions\UnprocessableEntityException;
use App\Tests\Support\RecordingStripeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * What the adapter sends, and what it makes of a refusal — with the SDK's
 * transport replaced, so nothing here needs a key that works.
 */
#[CoversClass(StripePaymentProvider::class)]
final class StripePaymentProviderTest extends TestCase
{
    private RecordingStripeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new RecordingStripeHttpClient();
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        $curl = CurlClient::instance();
        \assert($curl instanceof ClientInterface);
        ApiRequestor::setHttpClient($curl);
    }

    public function testItIsNamedStripeAndKnowsASandboxFromTheKey(): void
    {
        self::assertSame('stripe', $this->provider()->name());
        self::assertTrue($this->provider()->isSandbox());
        self::assertFalse($this->provider(live: true)->isSandbox());
        self::assertSame('pk_test_unit', $this->provider()->clientKey());
        self::assertTrue(StripePaymentProvider::isLiveKey('sk_live_abc'));
        self::assertFalse(StripePaymentProvider::isLiveKey('sk_test_abc'));
    }

    public function testAuthorizeCreatesOneIntentPerAttemptAndHandsBackItsSecret(): void
    {
        $this->http->answer(['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'client_secret' => 'pi_1_secret_x']);

        $started = $this->provider()->authorize(Money::of(4900, 'EUR'), 'F-2026-0042/1');

        self::assertSame('pi_1', $started->id);
        self::assertSame(PaymentStatus::PENDING, $started->status);
        self::assertSame('pi_1_secret_x', $started->clientSecret);
        self::assertNull($started->method);

        $request = $this->http->requests[0];
        self::assertSame('post', $request['method']);
        self::assertStringEndsWith('/v1/payment_intents', $request['url']);
        self::assertSame(4900, $request['params']['amount']);
        self::assertSame('eur', $request['params']['currency']);
        // Form-encoded by the SDK before the transport sees it, hence the string.
        self::assertSame(['enabled' => 'true'], $request['params']['automatic_payment_methods']);
        self::assertSame('F-2026-0042/1', $request['params']['description']);
        self::assertSame(['reference' => 'F-2026-0042/1'], $request['params']['metadata']);
        // The reference names the attempt (ADR-034), so the same attempt asked
        // twice is the same intent — and never the invoice number alone.
        self::assertSame('authorize_' . hash('sha256', 'F-2026-0042/1'), $this->http->header(0, 'Idempotency-Key'));
        // Nothing a customer gave is here: no card, no customer, no capture flag.
        self::assertArrayNotHasKey('payment_method', $request['params']);
        self::assertArrayNotHasKey('customer', $request['params']);
        self::assertArrayNotHasKey('capture_method', $request['params']);
    }

    public function testAThreeDecimalAmountThatStripeWouldRefuseIsRefusedHereFirst(): void
    {
        $this->expectException(UnprocessableEntityException::class);
        $this->expectExceptionMessageMatches('/thousandths/');

        // 12.345 KWD: Stripe counts fils but refuses a last digit other than 0.
        $this->provider()->authorize(Money::of(12345, 'KWD'), 'F-2026-0042/1');

        self::assertSame([], $this->http->requests);
    }

    public function testAThreeDecimalAmountEndingInZeroGoesThrough(): void
    {
        $this->http->answer(['id' => 'pi_2', 'object' => 'payment_intent', 'client_secret' => 's']);

        $this->provider()->authorize(Money::of(12340, 'KWD'), 'F-2026-0042/1');

        self::assertSame('kwd', $this->http->requests[0]['params']['currency']);
    }

    public function testARefusedKeyIsConfigurationNotAStackTrace(): void
    {
        $this->http->answer(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);

        try {
            $this->provider()->authorize(Money::of(100, 'EUR'), 'F-1/1');
            self::fail('expected a refusal');
        } catch (NotConfiguredException $refusal) {
            self::assertSame('PAYMENT_PROVIDER_REJECTED_KEY', $refusal->errorCode());
        }
    }

    public function testAnyOtherRefusalCarriesStripesCodeInTheDetails(): void
    {
        $this->http->answer(['error' => ['type' => 'invalid_request_error', 'code' => 'amount_too_small', 'message' => 'Amount must be at least €0.50']], 400);

        try {
            $this->provider()->authorize(Money::of(1, 'EUR'), 'F-1/1');
            self::fail('expected a refusal');
        } catch (UnprocessableEntityException $refusal) {
            self::assertSame('PAYMENT_PROVIDER_REFUSED', $refusal->errorCode());
            self::assertSame('amount_too_small', $refusal->details()['provider_code'] ?? null);
            // Stripe's sentence is written for merchants; it stays out of the message.
            self::assertStringNotContainsString('€0.50', $refusal->getMessage());
        }
    }

    public function testRefundAsksForTheIntentAndMapsTheReason(): void
    {
        $this->http->answer(['id' => 're_1', 'object' => 'refund', 'status' => 'pending']);

        $refund = $this->provider()->refund(self::payment('pi_1'), Money::of(1900, 'EUR'), 'REQUESTED');

        self::assertSame('re_1', $refund->id);
        // Settled by the webhook, never here.
        self::assertSame('PENDING', $refund->status);

        $params = $this->http->requests[0]['params'];
        self::assertStringEndsWith('/v1/refunds', $this->http->requests[0]['url']);
        self::assertSame('pi_1', $params['payment_intent']);
        self::assertSame(1900, $params['amount']);
        self::assertSame('requested_by_customer', $params['reason']);
        self::assertSame(['reason' => 'REQUESTED'], $params['metadata']);
        self::assertNotNull($this->http->header(0, 'Idempotency-Key'));
    }

    public function testRefundKeepsStripesOwnReasonsAsTheyAre(): void
    {
        $this->http->answer(['id' => 're_2', 'object' => 'refund']);
        $this->http->answer(['id' => 're_3', 'object' => 'refund']);

        $this->provider()->refund(self::payment('pi_1'), Money::of(100, 'EUR'), 'DUPLICATE');
        $this->provider()->refund(self::payment('pi_1'), Money::of(100, 'EUR'), 'FRAUDULENT');

        self::assertSame('duplicate', $this->http->requests[0]['params']['reason']);
        self::assertSame('fraudulent', $this->http->requests[1]['params']['reason']);
        // Two different refunds, two different keys.
        self::assertNotSame($this->http->header(0, 'Idempotency-Key'), $this->http->header(1, 'Idempotency-Key'));
    }

    public function testVerifyReadsTheStripeSignatureHeaderCaseInsensitively(): void
    {
        $body = '{"id":"evt_1"}';
        $now = 1_789_200_000;
        $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'whsec_unit');

        $this->provider()->verify($body, ['stripe-signature' => $signature]);
        $this->addToAssertionCount(1);

        $this->expectException(UnauthenticatedException::class);
        $this->provider()->verify($body, ['X-Payment-Signature' => $signature]);
    }

    private function provider(bool $live = false): StripePaymentProvider
    {
        return new StripePaymentProvider(
            new StripeClient(['api_key' => $live ? 'sk_live_unit' : 'sk_test_unit']),
            'whsec_unit',
            $live ? 'pk_live_unit' : 'pk_test_unit',
            $live,
            static fn (): int => 1_789_200_000,
        );
    }

    private static function payment(string $intent): Payment
    {
        return new Payment(
            'pay-1',
            'tenant-1',
            'product-1',
            'invoice-1',
            null,
            'stripe',
            $intent,
            PaymentStatus::SUCCEEDED,
            Money::of(4900, 'EUR'),
            'card',
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-09-08T16:00:00Z'),
        );
    }
}
