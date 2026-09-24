<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Product\Service\ProductResolver;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/token` — an email and a password for a session (U12).
 *
 * Public, and one of exactly three routes that are. It is also the most attacked
 * endpoint any application has, which is why the rate limiter sits *in front of*
 * authentication (§31): the tighter public allowance applies here, so guessing is
 * bounded before a single password is hashed.
 */
final class SignInController implements RouteHandler
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
        $body = JsonBody::of($request);

        // The address is trimmed, because a leading space in a typed email is a
        // typing artefact. The password is not — see JsonBody::requiredSecret.
        // The product the page is on, when it says (ADR-051 milestone E): the
        // token then names it too, so that product's own server can verify it
        // locally. A claim, not a decision — an unknown code names nothing.
        $session = $this->sessions->signIn(
            $body->requiredString('email'),
            $body->requiredSecret('password'),
            $request->getHeaderLine(ProductResolver::HEADER),
        );

        return RefreshCookie::set(
            new JsonResponse(SessionPresenter::one($session), 200),
            $request,
            $session->refreshToken,
            $session->refreshLifetimeSeconds,
            $this->cookieDomain,
        );
    }
}
