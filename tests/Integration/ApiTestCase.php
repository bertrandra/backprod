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
     * @param array<string, mixed>  $serverParams what a real server would put
     *                                            in $_SERVER — REMOTE_ADDR,
     *                                            for anything that has to know
     *                                            where a request came from
     * @param array<string, string> $cookies      what the browser would send
     *                                            back. Added for U12: the refresh
     *                                            token is an HttpOnly cookie, so a
     *                                            test that could not send one could
     *                                            not exercise staying signed in
     */
    protected function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null,
        array $serverParams = [],
        array $cookies = [],
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
            serverParams: $serverParams + ['REMOTE_ADDR' => $this->addressOfThisTest()],
            uri: $uri,
            method: $method,
            body: $stream,
            queryParams: $query,
            cookieParams: $cookies,
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
     * A caller address unique to this test, unless the test names its own.
     *
     * Every request now passes a rate limiter that counts per address, and
     * the counters are not cleared by tests that use no database. Without
     * this, unrelated tests would share one bucket, the suite would spend a
     * real allowance as it grew, and the first test to cross the line would
     * fail for a reason having nothing to do with what it was checking.
     *
     * Not a real address, and it does not need to be: the bucket is an opaque
     * string, and the point is only that two tests never collide.
     */
    private function addressOfThisTest(): string
    {
        return 'test-' . substr(hash('xxh128', static::class . '::' . $this->name()), 0, 16);
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

    /**
     * The production container, built with this test's overrides.
     *
     * A fresh one each call, which is fine for anything whose state lives
     * outside it — the database, the storage root — and is why a test that
     * reaches for a service here gets the same rows and the same files the
     * HTTP requests do. It is not a way to inspect an in-memory double: that
     * would be a second instance with its own memory.
     */
    protected function container(): ContainerInterface
    {
        $factory = require dirname(__DIR__, 2) . '/config/container.php';

        if (!is_callable($factory)) {
            throw new RuntimeException('config/container.php must return a callable.');
        }

        $container = $factory($this->overrides);

        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException('Container factory must return a PSR-11 container.');
        }

        return $container;
    }

    private function app(): RequestHandlerInterface
    {
        $app = $this->container()->get(RequestHandlerInterface::class);

        if (!$app instanceof RequestHandlerInterface) {
            throw new RuntimeException('Container must provide a PSR-15 request handler.');
        }

        return $app;
    }
}
