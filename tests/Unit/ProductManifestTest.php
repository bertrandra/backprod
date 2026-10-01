<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Product\Domain\ProductManifest;
use PHPUnit\Framework\TestCase;

/**
 * What a product is allowed to say about itself.
 *
 * This file is the boundary between a product's own repository and this
 * platform's configuration, and everything that crosses it is a claim by a host
 * — reachable over TLS, named by staff, and otherwise unverified. So what is
 * asserted here is mostly what is *refused*: the console offers an operator a
 * button based on this, and a button that applies nonsense is worse than no
 * button.
 */
final class ProductManifestTest extends TestCase
{
    public function testAManifestIsItsProductAndItsVersions(): void
    {
        $manifest = ProductManifest::parse([
            'product' => 'plan',
            'app_version' => '2.3.0',
            'schema_versions' => [3, 1, 2, 1],
        ]);

        self::assertNotNull($manifest);
        self::assertSame('plan', $manifest->product);
        self::assertSame('2.3.0', $manifest->appVersion);
        // Deduplicated and ascending, cleaned the way a stored row is — so a
        // manifest and the configuration it is compared against mean the same
        // thing by "a version".
        self::assertSame([1, 2, 3], $manifest->schemaVersions);
    }

    public function testTheReleaseIsOptionalBecauseItDecidesNothing(): void
    {
        $manifest = ProductManifest::parse(['product' => 'plan', 'schema_versions' => [1]]);

        self::assertNotNull($manifest);
        self::assertNull($manifest->appVersion);
    }

    public function testAnEmptyListIsNotAManifest(): void
    {
        // A product saying "I accept nothing" is indistinguishable here from a
        // file that lost its array, and acting on it would offer an operator a
        // button that takes the product offline. The contract says `minItems: 1`
        // for the same reason.
        self::assertNull(ProductManifest::parse(['product' => 'plan', 'schema_versions' => []]));

        // And a list whose every element is dropped is an empty list.
        self::assertNull(
            ProductManifest::parse(['product' => 'plan', 'schema_versions' => ['1', 0, -2]]),
        );
    }

    public function testWhatTheReaderWouldDropIsDropped(): void
    {
        // `"2"` and `3.0` come out of a hand-written JSON file constantly, and
        // casting them would invent a version the writer did not mean. Dropped
        // rather than refused, because the rest of the file is still an answer.
        $manifest = ProductManifest::parse([
            'product' => 'plan',
            'schema_versions' => [1, '2', 3.0, null, 4],
        ]);

        self::assertNotNull($manifest);
        self::assertSame([1, 4], $manifest->schemaVersions);
    }

    public function testAListLongerThanTheConfigurationCanHoldIsRefused(): void
    {
        // The same bound as `SchemaVersions::LIMIT`, so what a product can say
        // and what the platform can store are one number. Past it, this is a
        // mistake or a file that is not a manifest.
        $versions = range(1, ProductManifest::LIMIT + 1);

        self::assertNull(ProductManifest::parse(['product' => 'plan', 'schema_versions' => $versions]));
        self::assertNotNull(
            ProductManifest::parse(['product' => 'plan', 'schema_versions' => range(1, ProductManifest::LIMIT)]),
        );
    }

    public function testAFileMissingEitherRequiredKeyIsNotAManifest(): void
    {
        self::assertNull(ProductManifest::parse(['schema_versions' => [1]]));
        self::assertNull(ProductManifest::parse(['product' => 'plan']));
        self::assertNull(ProductManifest::parse(['product' => '  ', 'schema_versions' => [1]]));
        // What a single-page app answering its index for every path decodes to,
        // on the days it decodes at all.
        self::assertNull(ProductManifest::parse('<!doctype html>'));
        self::assertNull(ProductManifest::parse(null));
    }

    public function testUnknownKeysAreIgnoredBecauseTheFileIsTheProductS(): void
    {
        $manifest = ProductManifest::parse([
            'product' => 'plan',
            'schema_versions' => [1],
            'capabilities' => ['terrace', 'roof'],
            'contact' => 'ops@plan.example',
        ]);

        self::assertNotNull($manifest);
        self::assertSame([1], $manifest->schemaVersions);
    }

    public function testAManifestSaysWhichProductItIsFor(): void
    {
        // The platform checks this against the product it asked about. An
        // `app_url` copied between two products otherwise maps one product's
        // versions onto another's configuration, silently and plausibly.
        $manifest = ProductManifest::parse(['product' => 'Plan', 'schema_versions' => [1]]);

        self::assertNotNull($manifest);
        self::assertTrue($manifest->describes('plan'));
        self::assertTrue($manifest->describes('PLAN'));
        self::assertFalse($manifest->describes('atlas'));
        self::assertFalse($manifest->describes(''));
    }
}
