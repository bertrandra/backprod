<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Drives the real middleware pipeline in-process — no web server.
 *
 * Integration tests exercise the same container wiring production uses, so a
 * mistake in middleware ordering is caught here rather than in staging.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest(
            uri: 'https://api.test' . $path,
            method: $method,
        );

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Response body was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function app(): RequestHandlerInterface
    {
        $factory = require dirname(__DIR__, 2) . '/config/container.php';

        if (!is_callable($factory)) {
            throw new RuntimeException('config/container.php must return a callable.');
        }

        $container = $factory();

        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException('Container factory must return a PSR-11 container.');
        }

        $app = $container->get(RequestHandlerInterface::class);

        if (!$app instanceof RequestHandlerInterface) {
            throw new RuntimeException('Container must provide a PSR-15 request handler.');
        }

        return $app;
    }
}
