<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/sign-out` — end this session (U12).
 *
 * **Always 204, and always clears the cookie.** An unknown token, an expired one,
 * no cookie at all: every one of them ends with the person signed out, which is
 * what they asked for. Answering "401, your token had already expired" to somebody
 * trying to leave would be an error message in place of the thing they wanted, and
 * would leave the cookie in the browser.
 *
 * Revoking server-side is the half that matters. Clearing the cookie alone would
 * make the *browser* forget a credential that stayed valid for another month;
 * anybody holding a copy would still be signed in.
 */
final class SignOutController implements RouteHandler
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
        $this->sessions->signOut(RefreshCookie::read($request));

        return RefreshCookie::clear(new EmptyResponse(204), $request, $this->cookieDomain);
    }
}
