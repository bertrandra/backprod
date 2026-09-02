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
        // FastRoute returns an untyped array, so every element is narrowed
        // explicitly before it reaches typed code.
        $route = $this->dispatcher->dispatch(
            $request->getMethod(),
            rawurldecode($request->getUri()->getPath()),
        );

        $outcome = $route[0] ?? null;

        if ($outcome === Dispatcher::METHOD_NOT_ALLOWED) {
            throw new MethodNotAllowedException($this->allowedMethods($route[1] ?? null));
        }

        if ($outcome !== Dispatcher::FOUND) {
            throw new NotFoundException();
        }

        return $this->invoke($route[1] ?? null, $route[2] ?? null, $request);
    }

    /**
     * @return list<string>
     */
    private function allowedMethods(mixed $allowed): array
    {
        if (!is_array($allowed)) {
            return [];
        }

        $methods = [];

        foreach ($allowed as $method) {
            if (is_string($method)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    private function invoke(mixed $handlerId, mixed $arguments, ServerRequestInterface $request): ResponseInterface
    {
        if (!is_string($handlerId)) {
            throw new RuntimeException('Route handler must be referenced by class name.');
        }

        $handler = $this->container->get($handlerId);

        if (!$handler instanceof RouteHandler) {
            throw new RuntimeException(sprintf('Route handler %s must implement %s.', $handlerId, RouteHandler::class));
        }

        if (is_array($arguments)) {
            foreach ($arguments as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $request = $request->withAttribute($name, $value);
                }
            }
        }

        return $handler($request);
    }
}
