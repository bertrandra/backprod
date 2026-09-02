<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 pipeline that delegates to a final handler once exhausted.
 *
 * The cursor is advanced on a clone rather than on $this, so one pipeline
 * instance can serve concurrent or nested handling without the position
 * leaking between calls.
 *
 * From M1 this is where the context chain of §10.6 is assembled, in order:
 * authentication → product → tenant → role → entitlement → authorization.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $finalHandler,
        private readonly int $position = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $current = $this->middleware[$this->position] ?? null;

        if ($current === null) {
            return $this->finalHandler->handle($request);
        }

        return $current->process(
            $request,
            new self($this->middleware, $this->finalHandler, $this->position + 1),
        );
    }
}
