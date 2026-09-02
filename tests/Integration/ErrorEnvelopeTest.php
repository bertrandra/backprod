<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The error contract of Architecture V2 §10.4 is consumed by the generated
 * TypeScript client, so its shape is a promise rather than an implementation
 * detail. These tests pin it.
 */
#[CoversNothing]
final class ErrorEnvelopeTest extends ApiTestCase
{
    public function testUnknownRouteReturns404InTheDocumentedEnvelope(): void
    {
        $response = $this->request('GET', '/api/v1/does-not-exist');
        $error = $this->errorOf($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $error['code'] ?? null);
        self::assertArrayHasKey('message', $error);
        self::assertArrayHasKey('details', $error);
        self::assertArrayHasKey('request_id', $error);
    }

    public function testWrongMethodReturns405AndAdvertisesAllowedMethods(): void
    {
        $response = $this->request('DELETE', '/api/v1/health');
        $error = $this->errorOf($response);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('METHOD_NOT_ALLOWED', $error['code'] ?? null);
        self::assertSame(['allowed' => ['GET']], $error['details'] ?? null);
    }

    public function testErrorBodyCarriesTheSameCorrelationIdAsTheHeader(): void
    {
        $response = $this->request('GET', '/api/v1/nope', [
            RequestId::HEADER => 'correlate-me-please',
        ]);

        self::assertSame('correlate-me-please', $this->errorOf($response)['request_id'] ?? null);
        self::assertSame('correlate-me-please', $response->getHeaderLine(RequestId::HEADER));
    }
}
