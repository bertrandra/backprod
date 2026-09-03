<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Exceptions\ForbiddenException;
use App\Storage\Domain\Asset;
use App\Storage\Service\AssetLinks;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A signed link is the only way to fetch an asset without a session, so what
 * it refuses matters more than what it allows.
 */
#[CoversClass(AssetLinks::class)]
final class AssetLinksTest extends TestCase
{
    private const SECRET = 'a-signing-secret';
    private const ASSET = '11111111-1111-1111-1111-111111111111';

    public function testAFreshLinkVerifies(): void
    {
        $links = new AssetLinks(self::SECRET);
        [$expires, $signature] = $this->partsOf($links->mint($this->asset(), 300)['url']);

        $links->verify(self::ASSET, $expires, $signature);

        $this->expectNotToPerformAssertions();
    }

    public function testTheExpiryCannotBeExtended(): void
    {
        $links = new AssetLinks(self::SECRET);
        [$expires, $signature] = $this->partsOf($links->mint($this->asset(), 300)['url']);

        // The whole reason the expiry is inside the signed material. Without
        // that, moving the deadline is a query-string edit away.
        $this->expectException(ForbiddenException::class);

        $links->verify(self::ASSET, (string) ((int) $expires + 3600), $signature);
    }

    public function testALinkDoesNotWorkForAnotherAsset(): void
    {
        $links = new AssetLinks(self::SECRET);
        [$expires, $signature] = $this->partsOf($links->mint($this->asset(), 300)['url']);

        $this->expectException(ForbiddenException::class);

        $links->verify('22222222-2222-2222-2222-222222222222', $expires, $signature);
    }

    public function testALapsedLinkIsRefused(): void
    {
        $links = new AssetLinks(self::SECRET);
        $expired = (string) (time() - 1);

        // Signed correctly, and still refused: the clock decides, as it does
        // for offers, entitlements, quotes and job leases.
        $this->expectException(ForbiddenException::class);

        $links->verify(
            self::ASSET,
            $expired,
            hash_hmac('sha256', self::ASSET . "\n" . $expired, self::SECRET),
        );
    }

    public function testALinkSignedWithAnotherKeyIsRefused(): void
    {
        [$expires, $signature] = $this->partsOf((new AssetLinks(self::SECRET))->mint($this->asset(), 300)['url']);

        $this->expectException(ForbiddenException::class);

        (new AssetLinks('a-different-secret'))->verify(self::ASSET, $expires, $signature);
    }

    public function testWithNoSecretNothingVerifies(): void
    {
        [$expires, $signature] = $this->partsOf((new AssetLinks(self::SECRET))->mint($this->asset(), 300)['url']);

        // Fail closed, like the payment and PDP secrets. An empty key must
        // not make every link valid.
        $this->expectException(ForbiddenException::class);

        (new AssetLinks(''))->verify(self::ASSET, $expires, $signature);
    }

    public function testAnExpiryThatIsNotANumberIsRefused(): void
    {
        $links = new AssetLinks(self::SECRET);

        $this->expectException(ForbiddenException::class);

        $links->verify(self::ASSET, 'soon', str_repeat('a', 64));
    }

    public function testTheLifetimeIsCapped(): void
    {
        $link = (new AssetLinks(self::SECRET))->mint($this->asset(), 999_999);

        self::assertLessThanOrEqual(AssetLinks::MAX_TTL_SECONDS, $link['expires_at'] - time());
    }

    /**
     * @return array{string, string}
     */
    private function partsOf(string $url): array
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $expires = $query['expires'] ?? null;
        $signature = $query['signature'] ?? null;

        self::assertIsString($expires);
        self::assertIsString($signature);

        return [$expires, $signature];
    }

    private function asset(): Asset
    {
        return new Asset(
            self::ASSET,
            '33333333-3333-3333-3333-333333333333',
            '44444444-4444-4444-4444-444444444444',
            null,
            Asset::UPLOAD,
            'abcdef0123456789abcdef0123456789',
            'survey.png',
            'image/png',
            1024,
            str_repeat('a', 64),
            null,
            new DateTimeImmutable(),
        );
    }
}
