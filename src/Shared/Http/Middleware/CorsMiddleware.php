<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Strict CORS: an allowlist, and nothing for anybody not on it (§31).
 *
 * **No wildcard, ever.** This API is read with a bearer token, and
 * `Access-Control-Allow-Origin: *` cannot be combined with credentials — the
 * browser refuses. More to the point, a wildcard would let any page on the
 * internet make authenticated calls on a signed-in user's behalf, which is
 * the whole attack CORS exists to prevent. So the origin is echoed back only
 * when it is one we named.
 *
 * **An origin we do not know gets no CORS headers at all**, rather than a
 * rejection. That is what the specification asks for and it is the more
 * useful behaviour: the browser blocks the read, the server does not have to
 * decide whether a cross-origin request is hostile, and a same-origin or
 * server-to-server caller — which sends no `Origin` — is unaffected.
 *
 * **`Vary: Origin` on everything.** The response body is the same either way
 * but the headers are not, and a cache that missed that would serve one
 * tenant's allowed origin to another's browser.
 *
 * **A preflight never reaches the application.** `OPTIONS` carries no
 * credentials by design, so letting it through would be asking the context
 * chain to authenticate a request that deliberately has nothing to
 * authenticate with.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    /**
     * Every request header the API actually reads. A browser sends only
     * what is named here, so a header missing from this list is an
     * endpoint that works from curl and fails from the application.
     */
    private const ALLOWED_HEADERS =
        'Authorization, Content-Type, X-Product, X-Tenant, X-Request-Id, X-Filename';

    /**
     * How long a browser may cache the preflight. Ten minutes: long enough to
     * spare the round trip on a busy page, short enough that changing the
     * allowlist takes effect the same morning.
     */
    private const MAX_AGE = '600';

    /**
     * @param list<string> $allowedOrigins exact origins, scheme and host and
     *                                     port; empty disables CORS entirely
     */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && in_array($origin, $this->allowedOrigins, true);

        if (strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->preflight($origin, $allowed);
        }

        return $this->decorate($handler->handle($request), $origin, $allowed);
    }

    private function preflight(string $origin, bool $allowed): ResponseInterface
    {
        // 204 either way. Answering a disallowed preflight with an error would
        // tell a probing page which origins are configured, and the browser
        // blocks the real request just the same when the headers are absent.
        $response = (new Response())->withStatus(204)->withHeader('Vary', 'Origin');

        if (!$allowed) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::MAX_AGE);
    }

    private function decorate(ResponseInterface $response, string $origin, bool $allowed): ResponseInterface
    {
        $response = $response->withHeader('Vary', 'Origin');

        if (!$allowed) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            // Without this a browser can read the status and nothing else —
            // including the correlation id a client would quote in a bug
            // report, and the Retry-After that tells it when to come back.
            ->withHeader('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After');
    }
}
