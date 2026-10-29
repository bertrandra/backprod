<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tax\Domain\VatNumberCheck;
use App\Tax\Domain\VatNumberValidator;
use App\Tax\Infrastructure\StubVatNumberValidator;
use App\Tax\Infrastructure\UnavailableVatNumberValidator;
use App\Tax\Infrastructure\ViesVatNumberValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Which VAT-number verifier a deployment gets (2026-10-29).
 *
 * The stub was wired unconditionally, so production "verified" every number
 * not ending in 0 or 9 and reverse-charged on it, with `VIES` recorded as the
 * source. A deployment now gets the stub only by naming it; anything else
 * verifies nothing — fail closed, as §25.3 requires of an outage.
 */
#[CoversClass(UnavailableVatNumberValidator::class)]
final class VatNumberValidatorWiringTest extends TestCase
{
    private string|false $before = false;

    protected function setUp(): void
    {
        $this->before = getenv('VAT_NUMBER_VALIDATOR');
    }

    protected function tearDown(): void
    {
        putenv($this->before === false ? 'VAT_NUMBER_VALIDATOR' : 'VAT_NUMBER_VALIDATOR=' . $this->before);
        unset($_ENV['VAT_NUMBER_VALIDATOR']);
    }

    public function testADeploymentThatNamesNoVerifierVerifiesNothing(): void
    {
        putenv('VAT_NUMBER_VALIDATOR');
        unset($_ENV['VAT_NUMBER_VALIDATOR']);

        $validator = self::validator();

        self::assertInstanceOf(UnavailableVatNumberValidator::class, $validator);

        // A number the stub would have verified.
        $check = $validator->check('FR12345678901');

        self::assertSame(VatNumberCheck::UNAVAILABLE, $check->outcome);
        self::assertFalse($check->isValid());
        self::assertSame(UnavailableVatNumberValidator::NAME, $check->source);
    }

    public function testTheStubIsChosenOnlyByName(): void
    {
        $_ENV['VAT_NUMBER_VALIDATOR'] = 'stub';

        $validator = self::validator();

        self::assertInstanceOf(StubVatNumberValidator::class, $validator);
        // And it says so in the evidence, never `VIES`.
        self::assertSame(StubVatNumberValidator::NAME, $validator->check('FR12345678901')->source);
    }

    /**
     * The real service, by its name. Not asked anything here: this test
     * never reaches the network ({@see ViesVatNumberValidatorTest}).
     */
    public function testVerifyingAgainstViesIsChosenByName(): void
    {
        $_ENV['VAT_NUMBER_VALIDATOR'] = 'vies';

        self::assertInstanceOf(ViesVatNumberValidator::class, self::validator());
    }

    private static function validator(): VatNumberValidator
    {
        $factory = require dirname(__DIR__, 2) . '/config/container.php';

        if (!is_callable($factory)) {
            throw new RuntimeException('config/container.php must return a callable.');
        }

        $container = $factory([]);

        self::assertInstanceOf(ContainerInterface::class, $container);

        $validator = $container->get(VatNumberValidator::class);

        self::assertInstanceOf(VatNumberValidator::class, $validator);

        return $validator;
    }
}
