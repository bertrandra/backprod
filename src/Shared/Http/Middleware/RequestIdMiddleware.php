<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\RequestId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Assigns a correlation id to every request and echoes it on the response.
 *
 * Runs first so that anything downstream — including the error handler — can
 * read it from the request attributes.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $supplied = $request->getHeaderLine(RequestId::HEADER);

        $requestId = $supplied === ''
            ? RequestId::generate()
            : RequestId::fromClient($supplied);

        $response = $handler->handle(
            $request->withAttribute(RequestId::ATTRIBUTE, $requestId),
        );

        return $response->withHeader(RequestId::HEADER, $requestId->toString());
    }
}
