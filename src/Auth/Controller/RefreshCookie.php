<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * The refresh token's home: a cookie no script can read.
 *
 * This is the reason U12 moved issuance into PHP at all. While an external
 * provider issued the token, the browser had to hold it, and a browser has
 * nowhere to hold a secret that survives a reload and resists XSS — every option
 * it has (`localStorage`, `sessionStorage`, a readable cookie) is reachable by
 * injected script. `HttpOnly` is not, and only the server that issues the cookie
 * can set it.
 *
 * So the two credentials live in two places on purpose: the access token in the
 * response body, held in memory, expiring within the hour; the refresh token
 * here, unreadable, revocable, and never in reach of a script.
 */
final class RefreshCookie
{
    public const NAME = 'backprod_refresh';

    /**
     * Scoped to the three endpoints that consume it.
     *
     * A cookie on `/` is attached to every request the browser makes to this
     * origin — every API call, every asset — which is a long-lived credential
     * repeatedly on the wire for no reason. Here it is sent only to the paths that
     * exchange it.
     */
    public const PATH = '/api/v1/auth';

    public static function read(ServerRequestInterface $request): string
    {
        $value = $request->getCookieParams()[self::NAME] ?? null;

        return is_string($value) ? $value : '';
    }

    public static function set(
        ResponseInterface $response,
        ServerRequestInterface $request,
        #[SensitiveParameter] string $token,
        int $lifetimeSeconds,
    ): ResponseInterface {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::build($request, $token, $lifetimeSeconds),
        );
    }

    /**
     * The same cookie, emptied and expired.
     *
     * Every attribute must match the one being replaced — name, path, and the
     * rest — or the browser stores a *second* cookie instead of overwriting the
     * first, and the original keeps being sent. A sign-out that leaves the
     * credential in the browser is not a sign-out.
     */
    public static function clear(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', self::build($request, '', 0));
    }

    private static function build(
        ServerRequestInterface $request,
        #[SensitiveParameter] string $token,
        int $lifetimeSeconds,
    ): string {
        $attributes = [
            self::NAME . '=' . $token,
            'Path=' . self::PATH,
            'Max-Age=' . $lifetimeSeconds,
            // Unreadable by script. The whole point.
            'HttpOnly',
            // Strict, which is also this application's CSRF answer for these
            // three endpoints: a cross-site POST carries no cookie at all, so
            // there is nothing to forge. Nothing else in the platform is
            // cookie-authenticated — every other route reads a bearer token — so
            // the usual "Strict breaks incoming links" objection does not apply:
            // no link ever needs this cookie to be sent.
            'SameSite=Strict',
        ];

        if (self::isSecure($request)) {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }

    /**
     * Secure unless this is plainly a loopback address.
     *
     * The naive test — "is the scheme https" — is wrong behind a reverse proxy,
     * which is how shared hosting terminates TLS: the application sees `http` and
     * would omit `Secure` on a site that is https-only, leaving a month-long
     * credential willing to travel in clear text.
     *
     * So the default is `Secure` and the exception is loopback, which is
     * development and the browser test suite. Getting this backwards fails safely
     * in only one direction, and this is that direction.
     */
    private static function isSecure(ServerRequestInterface $request): bool
    {
        $host = $request->getUri()->getHost();

        return !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
