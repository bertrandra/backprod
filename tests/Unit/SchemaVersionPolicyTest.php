<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Product\Infrastructure\InMemoryProductRegistry;
use App\Project\Service\SchemaVersionPolicy;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which document schema versions a product accepts (non-negotiable #10).
 *
 * The list is configuration, which means it can be wrong. Every wrong shape
 * has to fail closed: a product whose accepted versions cannot be read
 * accepts nothing, because the alternative is accepting anything on the
 * strength of a typo.
 */
#[CoversClass(SchemaVersionPolicy::class)]
final class SchemaVersionPolicyTest extends TestCase
{
    private const PRODUCT = 'prod-atlas';

    public function testConfiguredVersionsAreReportedSorted(): void
    {
        $policy = self::policyFor(['supported' => [2, 1, 3]]);

        self::assertSame([1, 2, 3], $policy->supportedFor(self::PRODUCT));
    }

    public function testDuplicatesAreCollapsed(): void
    {
        $policy = self::policyFor(['supported' => [1, 1, 2]]);

        self::assertSame([1, 2], $policy->supportedFor(self::PRODUCT));
    }

    /**
     * A version written as a string or a float is a configuration mistake.
     * Coercing it would hide the mistake and accept documents under a version
     * nobody meant to publish.
     *
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('unreadableConfigurations')]
    public function testAnUnreadableConfigurationSupportsNothing(array $configuration): void
    {
        self::assertSame([], self::policyFor($configuration)->supportedFor(self::PRODUCT));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableConfigurations(): iterable
    {
        yield 'no key at all' => [[]];
        yield 'wrong key' => [['versions' => [1]]];
        yield 'not a list' => [['supported' => 1]];
        yield 'versions as strings' => [['supported' => ['1', '2']]];
        yield 'versions as floats' => [['supported' => [1.0, 2.5]]];
        yield 'zero and negatives' => [['supported' => [0, -1]]];
        yield 'empty list' => [['supported' => []]];
    }

    public function testAProductWithNoConfigurationSupportsNothing(): void
    {
        $policy = new SchemaVersionPolicy(new InMemoryProductRegistry());

        self::assertSame([], $policy->supportedFor(self::PRODUCT));
    }

    public function testASupportedVersionPasses(): void
    {
        $this->expectNotToPerformAssertions();

        self::policyFor(['supported' => [1, 2]])->assertSupported(self::PRODUCT, 2);
    }

    public function testAnUnsupportedVersionIsRefusedWithTheAlternatives(): void
    {
        try {
            self::policyFor(['supported' => [1, 2]])->assertSupported(self::PRODUCT, 3);
        } catch (HttpException $error) {
            self::assertSame(422, $error->statusCode());
            self::assertSame('UNSUPPORTED_SCHEMA_VERSION', $error->errorCode());
            self::assertSame([1, 2], $error->details()['supported'] ?? null);

            return;
        }

        self::fail('An unsupported schema version was accepted.');
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private static function policyFor(array $configuration): SchemaVersionPolicy
    {
        return new SchemaVersionPolicy(new InMemoryProductRegistry(
            configuration: [self::PRODUCT => [SchemaVersionPolicy::CONFIGURATION_KEY => $configuration]],
        ));
    }
}
