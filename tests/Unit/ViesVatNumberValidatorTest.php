<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\Domain\TaxIdentification;
use App\Tax\Domain\VatNumberCheck;
use App\Tax\Infrastructure\ViesVatNumberValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Asking VIES, with the wire replaced (2026-10-29).
 *
 * Never the network: the transport is a closure that answers what each case
 * needs and records what it was asked. What is proved is the mapping, which
 * is where a mistake costs money — only a clear `valid: true` verifies, and
 * every kind of doubt answers UNAVAILABLE rather than either verdict.
 */
#[CoversClass(ViesVatNumberValidator::class)]
final class ViesVatNumberValidatorTest extends TestCase
{
    /** The JSON the validator posted, or null when it asked nothing. */
    private ?string $posted = null;

    private function viesAnswering(int $status, string $body, ?string $error = null): ViesVatNumberValidator
    {
        $this->posted = null;

        return new ViesVatNumberValidator(function (string $payload) use ($status, $body, $error): array {
            $this->posted = $payload;

            return ['status' => $status, 'body' => $body, 'error' => $error];
        });
    }

    /**
     * @return array<mixed>
     */
    private function postedBody(): array
    {
        self::assertIsString($this->posted, 'VIES was asked');
        $decoded = json_decode($this->posted, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testAValidNumberIsVerifiedWithWhatViesSaidOfIt(): void
    {
        $check = $this->viesAnswering(200, (string) json_encode([
            'countryCode' => 'FR',
            'vatNumber' => '12345678901',
            'requestDate' => '2026-10-29T10:00:00.000Z',
            'valid' => true,
            'requestIdentifier' => 'WAPIAAAAZ',
            'name' => 'ACME SAS',
            'address' => '1 RUE DE LA PAIX 75002 PARIS',
        ]))->check('FR 123 456 789 01');

        self::assertSame(VatNumberCheck::VALID, $check->outcome);
        self::assertSame(TaxIdentification::VERIFIED, $check->identificationStatus());
        self::assertSame('ACME SAS', $check->registeredName);
        self::assertSame('1 RUE DE LA PAIX 75002 PARIS', $check->registeredAddress);
        self::assertSame(ViesVatNumberValidator::NAME, $check->source);
        self::assertSame(['countryCode' => 'FR', 'vatNumber' => '12345678901'], $this->postedBody());

        // The dated proof, in VIES's own words.
        self::assertSame('2026-10-29T10:00:00.000Z', $check->evidence['request_date'] ?? null);
        self::assertSame('WAPIAAAAZ', $check->evidence['request_identifier'] ?? null);
        self::assertSame(ViesVatNumberValidator::NAME, $check->evidence['provider'] ?? null);
    }

    public function testAnUnknownNumberIsInvalid(): void
    {
        $check = $this->viesAnswering(200, '{"countryCode":"FR","vatNumber":"12345678901","valid":false,"name":"---","address":"---"}')
            ->check('FR12345678901');

        self::assertSame(VatNumberCheck::INVALID, $check->outcome);
        self::assertNull($check->registeredName);
    }

    public function testInputViesRefusesAsMalformedIsInvalid(): void
    {
        $check = $this->viesAnswering(400, '{"actionSucceed":false,"errorWrappers":[{"error":"INVALID_INPUT","message":"..."}]}')
            ->check('FR12345678901');

        self::assertSame(VatNumberCheck::INVALID, $check->outcome);
    }

    public function testAMemberStateThatCannotAnswerLeavesTheNumberUnproved(): void
    {
        $inBody = $this->viesAnswering(200, '{"valid":false,"userError":"MS_UNAVAILABLE"}')->check('DE123456789');
        $asError = $this->viesAnswering(500, '{"actionSucceed":false,"errorWrappers":[{"error":"MS_UNAVAILABLE"}]}')->check('DE123456789');

        self::assertSame(VatNumberCheck::UNAVAILABLE, $inBody->outcome);
        self::assertSame(VatNumberCheck::UNAVAILABLE, $asError->outcome);
        self::assertSame(TaxIdentification::UNAVAILABLE, $asError->identificationStatus());
    }

    public function testEveryOtherServiceErrorIsUnavailableEvenBesideAVerdict(): void
    {
        foreach (['TIMEOUT', 'SERVICE_UNAVAILABLE', 'MS_MAX_CONCURRENT_REQ', 'SOMETHING_NEW'] as $code) {
            $check = $this->viesAnswering(200, '{"valid":true,"userError":"' . $code . '"}')->check('IT12345678901');

            self::assertSame(VatNumberCheck::UNAVAILABLE, $check->outcome, $code);
        }
    }

    public function testATimeoutIsUnavailable(): void
    {
        $check = $this->viesAnswering(0, '', ViesVatNumberValidator::TIMEOUT)->check('FR12345678901');

        self::assertSame(VatNumberCheck::UNAVAILABLE, $check->outcome);
        self::assertSame(ViesVatNumberValidator::TIMEOUT, $check->evidence['reason'] ?? null);
    }

    public function testAnAnswerThatIsNotTheJsonExpectedIsUnavailable(): void
    {
        $html = $this->viesAnswering(200, '<html>Maintenance</html>')->check('FR12345678901');
        $noVerdict = $this->viesAnswering(200, '{"countryCode":"FR"}')->check('FR12345678901');
        $stringVerdict = $this->viesAnswering(200, '{"valid":"true"}')->check('FR12345678901');
        $notOk = $this->viesAnswering(503, '{"valid":true}')->check('FR12345678901');

        self::assertSame(VatNumberCheck::UNAVAILABLE, $html->outcome);
        self::assertSame(VatNumberCheck::UNAVAILABLE, $noVerdict->outcome);
        self::assertSame(VatNumberCheck::UNAVAILABLE, $stringVerdict->outcome);
        self::assertSame(VatNumberCheck::UNAVAILABLE, $notOk->outcome);
    }

    /**
     * Greece is GR to ISO and EL to VIES; a number typed either way is asked
     * about as EL. Northern Ireland's XI is asked about as itself.
     */
    public function testThePrefixIsViesAndNotIso(): void
    {
        $vies = $this->viesAnswering(200, '{"valid":true}');

        $vies->check('GR123456789');
        $typedAsIso = $this->postedBody();
        $vies->check('EL123456789');
        $typedAsVies = $this->postedBody();
        $vies->check('XI123456789');
        $northernIreland = $this->postedBody();

        self::assertSame(['countryCode' => 'EL', 'vatNumber' => '123456789'], $typedAsIso);
        self::assertSame(['countryCode' => 'EL', 'vatNumber' => '123456789'], $typedAsVies);
        self::assertSame(['countryCode' => 'XI', 'vatNumber' => '123456789'], $northernIreland);
    }

    public function testANumberWithNoCountryIsInvalidWithoutAsking(): void
    {
        $check = $this->viesAnswering(200, '{"valid":true}')->check('123456789');

        self::assertSame(VatNumberCheck::INVALID, $check->outcome);
        self::assertNull($this->posted);
    }
}
