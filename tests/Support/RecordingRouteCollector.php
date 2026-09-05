<?php

declare(strict_types=1);

namespace App\Tests\Support;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use InvalidArgumentException;

/**
 * A route collector that remembers what it was given.
 *
 * `config/routes.php` is a closure over a FastRoute collector, and the
 * dispatcher it normally builds turns the paths into regexes — which answers
 * "does this URL match?" and not "what did we register?". The second question
 * is the one a test about the platform's exposed surface has to ask, so this
 * keeps the paths as written on their way through.
 */
final class RecordingRouteCollector extends RouteCollector
{
    /** @var list<array{method: string, path: string}> */
    private array $recorded = [];

    public function __construct()
    {
        parent::__construct(new Std(), new GroupCountBased());
    }

    /**
     * The signature is FastRoute's, verbatim, and deliberately so.
     *
     * `RouteCollector::addRoute()` types none of its parameters, so narrowing
     * `$route` to string here is a contravariance violation — an override
     * that accepts less than the method it replaces. Widening what a caller
     * may pass is safe; narrowing it is not, whatever the caller in fact
     * passes. So the types are checked in the body instead, where a wrong one
     * is a loud failure rather than a silent cast.
     *
     * @param string|string[] $httpMethod
     */
    public function addRoute($httpMethod, mixed $route, mixed $handler): void
    {
        if (!is_string($route)) {
            throw new InvalidArgumentException('A route path must be a string.');
        }

        foreach ((array) $httpMethod as $method) {
            if (!is_string($method)) {
                throw new InvalidArgumentException('An HTTP method must be a string.');
            }

            $this->recorded[] = ['method' => $method, 'path' => $route];
        }

        parent::addRoute($httpMethod, $route, $handler);
    }

    /**
     * Every route the application registers, in the order it registered them.
     *
     * @return list<array{method: string, path: string}>
     */
    public function routes(): array
    {
        return $this->recorded;
    }
}
