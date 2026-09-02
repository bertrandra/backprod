<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\Middleware\ErrorHandlerMiddleware;
use App\Shared\Http\RequestId;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

#[CoversClass(ErrorHandlerMiddleware::class)]
final class ErrorHandlerMiddlewareTest extends TestCase
{
    /**
     * CLAUDE.md forbids exposing stack traces or SQL errors. This is the test
     * that keeps that promise honest: a driver-level message containing a
     * credential must not survive into the response in any form.
     */
    public function testUnexpectedFailureBecomesAControlled500ThatLeaksNothing(): void
    {
        $leaky = 'SQLSTATE[42P01]: undefined_table, host=db.internal user=admin password=hunter2';

        $response = $this->process(new RuntimeException($leaky));
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('db.internal', $body);
        self::assertStringNotContainsString(__FILE__, $body);

        $error = $this->errorOf($response);
        self::assertSame('INTERNAL_ERROR', $error['code'] ?? null);
        self::assertSame('An unexpected error occurred.', $error['message'] ?? null);
    }

    public function testDomainHttpExceptionKeepsItsDocumentedStatusAndCode(): void
    {
        $response = $this->process(new NotFoundException('Project not found.'));
        $error = $this->errorOf($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $error['code'] ?? null);
        self::assertSame('Project not found.', $error['message'] ?? null);
    }

    public function testCorrelationIdReachesTheEnvelope(): void
    {
        $requestId = $this->errorOf($this->process(new RuntimeException('boom')))['request_id'] ?? null;

        self::assertIsString($requestId);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $requestId);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorOf(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (!is_array($error)) {
            throw new RuntimeException('Response did not carry an error envelope.');
        }

        /** @var array<string, mixed> $error */
        return $error;
    }

    private function process(Throwable $thrown): ResponseInterface
    {
        $middleware = new ErrorHandlerMiddleware(new NullLogger());

        $request = (new ServerRequest(uri: 'https://api.test/api/v1/thing', method: 'GET'))
            ->withAttribute(RequestId::ATTRIBUTE, RequestId::generate());

        $failing = new class ($thrown) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $thrown)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        };

        return $middleware->process($request, $failing);
    }
}
