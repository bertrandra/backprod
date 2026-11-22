<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Product\Domain\DemonstrationSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a product keeps its demonstrations (2026-11-22).
 *
 * The read these two strings drive leaves the platform's host, so what is
 * proved here is the boundary: nothing an operator can type sends it
 * anywhere but under the product's own address.
 */
final class DemonstrationSourceTest extends TestCase
{
    public function testItComposesBothAddressesUnderTheProductsOwn(): void
    {
        $source = DemonstrationSource::chosen(' admin/vitrine/{n} ', '?mode=demo&file={n}&x={width}&y={height}');

        self::assertNotNull($source);
        self::assertSame('https://plan.example.test/admin/vitrine/4', $source->sourceUrl('https://plan.example.test/', 4));
        // `{width}` and `{height}` are the page's to fill, and are left.
        self::assertSame(
            'https://plan.example.test/?mode=demo&file=12&x={width}&y={height}',
            $source->embedUrl('https://plan.example.test', 12),
        );
        self::assertSame($source->toArray(), DemonstrationSource::read($source->toArray())?->toArray());
    }

    #[DataProvider('unusable')]
    public function testItRefusesWhatCouldLeaveTheProductsAddress(string $source, string $embed): void
    {
        self::assertNull(DemonstrationSource::chosen($source, $embed));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusable(): iterable
    {
        $embed = '?file={n}';

        yield 'a scheme' => ['https://elsewhere.test/{n}', $embed];
        yield 'a scheme without slashes' => ['javascript:{n}', $embed];
        yield 'a host' => ['//elsewhere.test/{n}', $embed];
        yield 'a leading slash' => ['/admin/{n}', $embed];
        yield 'a parent segment' => ['../admin/{n}', $embed];
        yield 'a backslash' => ['admin\\{n}', $embed];
        yield 'credentials' => ['user@elsewhere.test/{n}', $embed];
        yield 'a space' => ['admin/ {n}', $embed];
        yield 'no number in the source' => ['admin/vitrine', $embed];
        yield 'no number in the embed' => ['admin/vitrine/{n}', '?mode=demo'];
        yield 'the embed leaving instead' => ['admin/vitrine/{n}', 'https://elsewhere.test/?file={n}'];
        yield 'one without the other' => ['admin/vitrine/{n}', ''];
        yield 'too long' => [str_repeat('a', 500) . '{n}', $embed];
    }

    public function testNothingStoredIsNothingConfigured(): void
    {
        self::assertNull(DemonstrationSource::read(null));
        self::assertNull(DemonstrationSource::read([]));
        self::assertNull(DemonstrationSource::read(['source' => 'admin/{n}']));
        self::assertNull(DemonstrationSource::read(['source' => 'https://elsewhere.test/{n}', 'embed' => '?file={n}']));
    }
}
