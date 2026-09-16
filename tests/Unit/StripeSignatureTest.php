<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Payment\Infrastructure\Stripe\StripeSignature;
use App\Shared\Exceptions\UnauthenticatedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The thirty lines that decide whether money moves on somebody else's
 * say-so (ADR-048).
 *
 * Two acceptances and five refusals, against a fixed secret and a fixed
 * clock, so every vector here is reproducible by hand:
 * `printf '%s.%s' "$t" "$body" | openssl dgst -sha256 -hmac "$secret"`.
 */
#[CoversClass(StripeSignature::class)]
final class StripeSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_0123456789abcdef';
    private const BODY = '{"id":"evt_1","type":"payment_intent.succeeded"}';
    private const NOW = 1_789_200_000;

    public function testAValidSignatureWithinTheWindowIsAccepted(): void
    {
        StripeSignature::verify(self::BODY, self::header(self::NOW - 30, self::sign(self::NOW - 30)), self::SECRET, self::NOW);

        $this->addToAssertionCount(1);
    }

    public function testAnyOfSeveralSignaturesIsEnoughDuringARotation(): void
    {
        // The old secret's signature first, the current one second: Stripe
        // sends both while an endpoint secret is being rolled.
        $stale = hash_hmac('sha256', (self::NOW - 10) . '.' . self::BODY, 'whsec_the_previous_secret');
        $header = 't=' . (self::NOW - 10) . ',v1=' . $stale . ',v1=' . self::sign(self::NOW - 10);

        StripeSignature::verify(self::BODY, $header, self::SECRET, self::NOW);

        $this->addToAssertionCount(1);
    }

    public function testAMissingHeaderIsRefused(): void
    {
        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(self::BODY, null, self::SECRET, self::NOW);
    }

    public function testAWrongSecretIsRefused(): void
    {
        $wrong = hash_hmac('sha256', self::NOW . '.' . self::BODY, 'whsec_somebody_elses');

        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(self::BODY, self::header(self::NOW, $wrong), self::SECRET, self::NOW);
    }

    public function testAnAlteredBodyIsRefused(): void
    {
        // Signed over one body, presented with another: the amount changed in
        // transit is exactly what verification over the raw bytes catches.
        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(
            str_replace('evt_1', 'evt_2', self::BODY),
            self::header(self::NOW, self::sign(self::NOW)),
            self::SECRET,
            self::NOW,
        );
    }

    public function testATimestampOutsideTheWindowIsRefusedEvenWhenTheSignatureIsRight(): void
    {
        $then = self::NOW - StripeSignature::TOLERANCE_SECONDS - 1;

        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(self::BODY, self::header($then, self::sign($then)), self::SECRET, self::NOW);
    }

    public function testAMalformedHeaderIsRefused(): void
    {
        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(self::BODY, 'v1=' . self::sign(self::NOW), self::SECRET, self::NOW);
    }

    public function testAnEmptySecretRefusesEverything(): void
    {
        // A deployment with no webhook secret must not verify by accident
        // against an empty key — fail closed, like the registry does.
        $this->expectException(UnauthenticatedException::class);

        StripeSignature::verify(self::BODY, self::header(self::NOW, hash_hmac('sha256', self::NOW . '.' . self::BODY, '')), '', self::NOW);
    }

    private static function sign(int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp . '.' . self::BODY, self::SECRET);
    }

    private static function header(int $timestamp, string $signature): string
    {
        return 't=' . $timestamp . ',v1=' . $signature;
    }
}
