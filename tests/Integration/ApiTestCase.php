<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Drives the real middleware pipeline in-process — no web server.
 *
 * Integration tests exercise the production container wiring, so a mistake in
 * middleware ordering is caught here rather than in staging. Only the leaf
 * dependencies (identity provider, repositories) are replaced, which is the
 * point: the security chain under test is the real one.
 */
abstract class ApiTestCase extends TestCase
{
    /** @var array<string, mixed> */
    private array $overrides = [];

    /**
     * Replace container definitions for this test.
     *
     * @param array<string, mixed> $definitions
     */
    protected function override(array $definitions): void
    {
        $this->overrides = $definitions + $this->overrides;
    }

    /**
     * A $body is sent as JSON, which is the only content type the API reads.
     *
     * Query parameters are parsed from the path rather than passed
     * separately, because that is what a real server does: PSR-7 does not
     * derive them from the URI, so a test that set them by hand would be
     * proving the handler works on input the server never produces.
     *
     * @param array<string, string> $headers
     */
    protected function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
    ): ResponseInterface {
        $uri = 'https://api.test' . $path;

        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        // The body is built before the request rather than written into it
        // afterwards: a ServerRequest defaults to php://input, which is
        // read-only, so writing to the stream it already has throws.
        $stream = new Stream('php://temp', 'wb+');

        if ($body !== null) {
            $stream->write($body);
            $stream->rewind();
        }

        $request = new ServerRequest(
            uri: $uri,
            method: $method,
            body: $stream,
            queryParams: $query,
        );

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->handle($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function json(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR);
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

    /**
     * The `error` object of the §10.4 envelope, as a typed array.
     *
     * Returning it typed keeps assertions free of offset access on mixed,
     * which static analysis rejects at this level.
     *
     * @return array<string, mixed>
     */
    protected function errorOf(ResponseInterface $response): array
    {
        $error = $this->decode($response)['error'] ?? null;

        if (!is_array($error)) {
            throw new RuntimeException('Response did not carry an error envelope.');
        }

        /** @var array<string, mixed> $error */
        return $error;
    }

    private function app(): RequestHandlerInterface
    {
        $factory = require dirname(__DIR__, 2) . '/config/container.php';

        if (!is_callable($factory)) {
            throw new RuntimeException('config/container.php must return a callable.');
        }

        $container = $factory($this->overrides);

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
