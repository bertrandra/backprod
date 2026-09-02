<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\MethodNotAllowedException;
use App\Shared\Exceptions\NotFoundException;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Final handler of the pipeline: resolves the route and invokes its handler.
 *
 * Handlers are pulled from the container by class name, so a controller
 * declares its dependencies in its constructor and never reaches for globals.
 */
final class Router implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly ContainerInterface $container,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $this->dispatcher->dispatch(
            $request->getMethod(),
            rawurldecode($request->getUri()->getPath()),
        );

        return match ($route[0]) {
            Dispatcher::NOT_FOUND => throw new NotFoundException(),
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowedException(
                array_values(array_map(strval(...), $route[1])),
            ),
            default => $this->invoke($route[1], $route[2], $request),
        };
    }

    /**
     * @param array<string, string> $arguments
     */
    private function invoke(mixed $handlerId, array $arguments, ServerRequestInterface $request): ResponseInterface
    {
        if (!is_string($handlerId)) {
            throw new RuntimeException('Route handler must be referenced by class name.');
        }

        $handler = $this->container->get($handlerId);

        if (!$handler instanceof RouteHandler) {
            throw new RuntimeException(sprintf('Route handler %s must implement %s.', $handlerId, RouteHandler::class));
        }

        foreach ($arguments as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $handler($request);
    }
}
