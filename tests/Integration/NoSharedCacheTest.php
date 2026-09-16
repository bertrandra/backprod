<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * No answer of this API may be kept by a cache in front of it.
 *
 * The first real host's reverse proxy cached `GET /me` by URL — the
 * response carried no `Cache-Control`, so it was fair game — and served
 * the first person's identity to the second. Every response, whatever its
 * status, now says `no-store`; this pins it on a 200, a refusal and a
 * route nobody has, because the proxy does not care which it is caching.
 */
#[CoversNothing]
final class NoSharedCacheTest extends ApiTestCase
{
    public function testAnAnswerForbidsEveryCache(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        // The host's own bypass switch, so its proxy never has to read the rest.
        self::assertSame('False', $response->getHeaderLine('X-Cache-Enabled'));
        // For a cache that keys anyway: the headers that decide the answer.
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Vary'));
    }

    public function testARefusalForbidsEveryCacheToo(): void
    {
        // A cached 401 is the other way round: the person who *is* signed in
        // gets the refusal somebody else earned.
        $response = $this->request('GET', '/api/v1/me', ['X-Product' => 'atlas']);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAnUnknownRouteForbidsEveryCacheToo(): void
    {
        // Refused before it is even routed — an unknown path is not told it
        // is unknown to a stranger — and refused with the same header.
        $response = $this->request('GET', '/api/v1/no-such-thing');

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
