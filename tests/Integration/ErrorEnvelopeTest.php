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
        $body = $this->decode($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);
        self::assertSame('NOT_FOUND', $body['error']['code'] ?? null);
        self::assertArrayHasKey('message', $body['error']);
        self::assertArrayHasKey('details', $body['error']);
        self::assertArrayHasKey('request_id', $body['error']);
    }

    public function testWrongMethodReturns405AndAdvertisesAllowedMethods(): void
    {
        $response = $this->request('DELETE', '/api/v1/health');
        $body = $this->decode($response);

        self::assertSame(405, $response->getStatusCode());
        self::assertIsArray($body['error']);
        self::assertSame('METHOD_NOT_ALLOWED', $body['error']['code'] ?? null);
        self::assertSame(['allowed' => ['GET']], $body['error']['details'] ?? null);
    }

    public function testErrorBodyCarriesTheSameCorrelationIdAsTheHeader(): void
    {
        $response = $this->request('GET', '/api/v1/nope', [
            RequestId::HEADER => 'correlate-me-please',
        ]);

        $body = $this->decode($response);
        self::assertIsArray($body['error']);

        self::assertSame('correlate-me-please', $body['error']['request_id'] ?? null);
        self::assertSame('correlate-me-please', $response->getHeaderLine(RequestId::HEADER));
    }
}
