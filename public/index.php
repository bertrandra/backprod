<?php

declare(strict_types=1);

use App\Shared\Http\ErrorResponse;
use Dotenv\Dotenv;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

require __DIR__ . '/../vendor/autoload.php';

// Immutable: a value already set in the real environment wins over .env, so a
// deployment's configuration cannot be overridden by a stray file.
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

/**
 * Everything from here is inside one try, and the reason is a deployment.
 *
 * `ErrorHandlerMiddleware` turns every throwable into the §10.4 envelope and
 * never serialises a trace — but it can only catch what happens *inside*
 * `$handler->handle()`, and building the container happens before any middleware
 * exists. An unconfigured `DATABASE_DSN` throws right here, during construction,
 * and escapes the guarantee entirely: what the browser then receives is decided
 * by the host's `display_errors`, not by this application.
 *
 * On a machine you own that is a 500 with an empty body. On shared hosting, where
 * `php.ini` is somebody else's decision, it is a stack trace with absolute paths —
 * and CLAUDE.md's "never expose stack traces" is not a setting to be inherited.
 *
 * So the envelope is produced here too. The detail goes to the error log, which
 * is where a trace is safe to read.
 */
try {
    $containerFactory = require __DIR__ . '/../config/container.php';
    assert(is_callable($containerFactory));

    /** @var ContainerInterface $container */
    $container = $containerFactory();

    /** @var RequestHandlerInterface $app */
    $app = $container->get(RequestHandlerInterface::class);

    $response = $app->handle(ServerRequestFactory::fromGlobals());
} catch (Throwable $e) {
    // Logged in full, including the trace: `error_log` writes where the host has
    // decided errors go, and nothing about it reaches the response.
    error_log(sprintf(
        'Backprod failed to start: %s: %s in %s:%d%s%s',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        $e->getTraceAsString(),
    ));

    /**
     * 503 rather than 500, and deliberately.
     *
     * The platform is not broken; it has not been given what it needs. A 503 says
     * "not able to serve this yet", which is what an operator halfway through a
     * deployment should read — and it keeps the honest failure of a half-configured
     * install distinguishable from a bug in a request handler, which is what the
     * middleware's controlled 500 means.
     *
     * There is no request id: the middleware that mints one never ran. Empty
     * rather than invented, because a correlation id that correlates with nothing
     * sends whoever reads it looking through logs for a request that has none.
     */
    $response = ErrorResponse::create(
        503,
        'SERVICE_UNAVAILABLE',
        'The service is not available. If you are deploying, check the server log and run bin/preflight.php.',
        [],
        '',
    );
}

(new SapiEmitter())->emit($response);
