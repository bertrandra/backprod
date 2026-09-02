<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Exceptions\HttpException;
use App\Shared\Http\ErrorResponse;
use App\Shared\Http\RequestId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns every thrown error into the documented envelope (§10.4).
 *
 * Unexpected throwables become a *controlled* 500 (§37.9): the diagnostic
 * detail is logged server-side against the request id and never serialised
 * into the response. CLAUDE.md forbids exposing stack traces or SQL errors,
 * and that holds in every environment — debug mode does not relax it, since
 * the safe way to read a trace is the log, not the wire.
 */
final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->requestId($request);

        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $this->logger->info('Request rejected', [
                'request_id' => $requestId,
                'status' => $e->statusCode(),
                'code' => $e->errorCode(),
            ]);

            return ErrorResponse::create(
                $e->statusCode(),
                $e->errorCode(),
                $e->getMessage(),
                $e->details(),
                $requestId,
            );
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'request_id' => $requestId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ErrorResponse::create(
                500,
                'INTERNAL_ERROR',
                'An unexpected error occurred.',
                [],
                $requestId,
            );
        }
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $requestId = $request->getAttribute(RequestId::ATTRIBUTE);

        return $requestId instanceof RequestId ? $requestId->toString() : 'unknown';
    }
}
