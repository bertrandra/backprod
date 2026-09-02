<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class HealthEndpointTest extends ApiTestCase
{
    public function testHealthReturnsOk(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->decode($response));
    }

    public function testEveryResponseCarriesACorrelationId(): void
    {
        $response = $this->request('GET', '/api/v1/health');

        self::assertNotSame('', $response->getHeaderLine(RequestId::HEADER));
    }

    public function testClientSuppliedCorrelationIdIsEchoed(): void
    {
        $response = $this->request('GET', '/api/v1/health', [
            RequestId::HEADER => 'trace-from-the-caller-1234',
        ]);

        self::assertSame('trace-from-the-caller-1234', $response->getHeaderLine(RequestId::HEADER));
    }

    /**
     * A correlation id is echoed into responses and written into logs, so a
     * value outside the safe character set must be discarded rather than
     * reflected. PSR-7 already rejects CRLF at construction; this covers what
     * it lets through.
     *
     * @param non-empty-string $hostile
     */
    #[DataProvider('unsafeCorrelationIds')]
    public function testUnsafeClientCorrelationIdIsReplaced(string $hostile): void
    {
        $response = $this->request('GET', '/api/v1/health', [
            RequestId::HEADER => $hostile,
        ]);

        $echoed = $response->getHeaderLine(RequestId::HEADER);

        self::assertNotSame($hostile, $echoed);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $echoed);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unsafeCorrelationIds(): iterable
    {
        yield 'log forging via separators' => ['id" level="critical'];
        yield 'too short to be meaningful' => ['abc'];
        yield 'unbounded length' => [str_repeat('a', 400)];
        yield 'disallowed characters' => ['id with spaces'];
    }
}
