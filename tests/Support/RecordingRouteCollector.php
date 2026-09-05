<?php

declare(strict_types=1);

namespace App\Tests\Support;

use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;

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
     * @param string|list<string> $httpMethod
     */
    public function addRoute($httpMethod, string $route, mixed $handler): void
    {
        foreach ((array) $httpMethod as $method) {
            $this->recorded[] = ['method' => (string) $method, 'path' => $route];
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
