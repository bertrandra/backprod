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

    /**
     * The host the cookie belongs to, or none (2026-09-24).
     *
     * **Empty is host-only**, which is the default and the right answer for a
     * deployment served from one host: the browser sends the cookie back to
     * exactly the host that set it and to nothing else.
     *
     * `AUTH_COOKIE_DOMAIN=raillard.org` widens it to that registrable domain
     * and its subdomains, which is what ADR-051 §3 promises and had not
     * built. The ADR reasoned about `SameSite=Strict` — a rule about *site*,
     * so `plan.raillard.org` calling the platform is same-site and allowed —
     * and stopped one step short: a cookie with no `Domain` is **host-only**,
     * and host-only does not match a sibling subdomain, whatever SameSite
     * says. So single sign-on did not happen, and the operator found it the
     * way these are always found — somebody who had just bought a seat was
     * asked for their password on the way to the thing they had bought.
     *
     * It bites inside one deployment too. This platform answers on
     * `raillard.org` *and* `www.raillard.org`, neither redirecting to the
     * other: signing in on one leaves a cookie the other never receives, so
     * whether a reload resumes depends on which of two addresses somebody
     * typed.
     *
     * **It is a widening of a credential's reach, so it is opt-in and it is
     * named.** Every host under the configured domain can receive this
     * refresh token — on `PATH` only, over `Secure`, unreadable by script,
     * and a deployment that puts something it does not trust on a subdomain
     * must not set this. A leading dot is accepted and dropped: RFC 6265
     * ignores it, and an operator who writes one should get what they meant
     * rather than a cookie the browser quietly discards.
     */
    public static function domainFrom(?string $configured): string
    {
        $domain = trim($configured ?? '');

        return ltrim($domain, '.');
    }

    /**
     * Every `backprod_refresh` the browser sent, in the order it sent them.
     *
     * **There can be two, and that is the whole reason this reads the raw
     * header** (2026-09-26). A cookie's identity is name *and domain* and
     * path: a host-only `backprod_refresh` and a `Domain=example.org` one are
     * two different cookies, so the day an operator sets
     * `AUTH_COOKIE_DOMAIN` the browser does not replace the first — it keeps
     * both and sends both, and it goes on doing so until each is expired by
     * a `Set-Cookie` that matches it exactly.
     *
     * `getCookieParams()` cannot see that. Diactoros parses the header into
     * `[$name => $value]`, so of two cookies with one name **one is silently
     * dropped** and which one depends on the order the browser chose. When
     * the survivor is the stale host-only twin, its token is already spent,
     * `Sessions::refresh()` reads that as theft and revokes every session
     * for the account — on every attempt, so the account stays stuck. The
     * operator's own deployment is how this was found.
     *
     * So the plural is the honest signature, and the caller decides what to
     * do with more than one. {@see Sessions::refresh()} does: a live token
     * among them wins, and only when none is live does a spent one mean what
     * it usually means.
     *
     * @return list<string> possibly empty, never containing an empty string
     */
    public static function presented(ServerRequestInterface $request): array
    {
        $found = [];

        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            $at = strpos($pair, '=');

            if ($at === false) {
                continue;
            }

            if (trim(substr($pair, 0, $at)) !== self::NAME) {
                continue;
            }

            $value = rawurldecode(trim(substr($pair, $at + 1), " \t\""));

            if ($value !== '') {
                $found[] = $value;
            }
        }

        // The parsed parameters as a fallback, for a caller that built the
        // request without a `Cookie` header — every test that says
        // `withCookieParams()` does exactly that, and so does any SAPI that
        // hands PHP `$_COOKIE` and no header.
        if ($found === []) {
            $value = $request->getCookieParams()[self::NAME] ?? null;

            if (is_string($value) && $value !== '') {
                $found[] = $value;
            }
        }

        return $found;
    }

    public static function set(
        ResponseInterface $response,
        ServerRequestInterface $request,
        #[SensitiveParameter] string $token,
        int $lifetimeSeconds,
        string $domain = '',
    ): ResponseInterface {
        return self::withoutTheTwin(
            $response->withAddedHeader(
                'Set-Cookie',
                self::build($request, $token, $lifetimeSeconds, $domain),
            ),
            $request,
            $domain,
        );
    }

    /**
     * Expires the host-only cookie this one does not replace (2026-09-26).
     *
     * A `Set-Cookie` matches an existing cookie on name, domain and path.
     * Adding a `Domain` therefore does not overwrite the host-only cookie of
     * the same name — it creates a second one, and the browser sends both
     * from then on. Sending one of each is how the pair ends: this one has no
     * `Domain`, so it matches the host-only twin and empties it.
     *
     * Only when a domain is configured. Without one, the cookie being written
     * *is* the host-only cookie, and the twin that might exist is a
     * `Domain=` one whose domain this code does not know — nothing can be
     * written to match it, which is worth knowing rather than pretending
     * otherwise. Unsetting `AUTH_COOKIE_DOMAIN` after using it leaves a
     * cookie only the browser can clear.
     */
    private static function withoutTheTwin(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $domain,
    ): ResponseInterface {
        if ($domain === '') {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', self::build($request, '', 0, ''));
    }

    /**
     * The same cookie, emptied and expired.
     *
     * Every attribute must match the one being replaced — name, path, and the
     * rest — or the browser stores a *second* cookie instead of overwriting the
     * first, and the original keeps being sent. A sign-out that leaves the
     * credential in the browser is not a sign-out.
     */
    public static function clear(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $domain = '',
    ): ResponseInterface {
        return self::withoutTheTwin(
            $response->withAddedHeader('Set-Cookie', self::build($request, '', 0, $domain)),
            $request,
            $domain,
        );
    }

    private static function build(
        ServerRequestInterface $request,
        #[SensitiveParameter] string $token,
        int $lifetimeSeconds,
        string $domain = '',
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

        // Absent means host-only, which is what a single-host deployment
        // wants and what every deployment got until 2026-09-24. Present, the
        // browser sends it to that domain and its subdomains — which is what
        // makes one sign-in serve the platform and a product beside it.
        if ($domain !== '') {
            $attributes[] = 'Domain=' . $domain;
        }

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
