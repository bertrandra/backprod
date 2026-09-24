<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Product\Service\ProductResolver;
use App\Shared\Exceptions\UnauthenticatedException;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/refresh` — a new access token, from the cookie (U12).
 *
 * **Takes no body.** The credential is the cookie, and the browser attaches it
 * without being asked. A body field would mean a script had read the token, which
 * is exactly what `HttpOnly` exists to prevent — so accepting one here would
 * quietly reopen the hole the cookie closes.
 *
 * The new refresh token replaces the old one on every call. Rotation is what makes
 * a stolen cookie detectable: the thief and the real client cannot both use the
 * same token, and whichever presents the spent one triggers the revocation of
 * every session for that account.
 */
final class RefreshSessionController implements RouteHandler
{
    /**
     * @param string $cookieDomain where the refresh cookie belongs — empty is
     *                             host-only, which is the default
     *                             ({@see RefreshCookie::domainFrom()})
     */
    public function __construct(
        private readonly Sessions $sessions,
        private readonly string $cookieDomain = '',
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $presented = RefreshCookie::read($request);

        if ($presented === '') {
            throw new UnauthenticatedException();
        }

        // Renewed on a product's page, the token names that product too
        // (ADR-051 milestone E) — which is how single sign-on from the cookie
        // yields a token the product's server can verify as its own.
        $session = $this->sessions->refresh($presented, $request->getHeaderLine(ProductResolver::HEADER));

        return RefreshCookie::set(
            new JsonResponse(SessionPresenter::one($session), 200),
            $request,
            $session->refreshToken,
            $session->refreshLifetimeSeconds,
            $this->cookieDomain,
        );
    }
}
