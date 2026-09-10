<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
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
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::of($request);

        // The address is trimmed, because a leading space in a typed email is a
        // typing artefact. The password is not — see JsonBody::requiredSecret.
        $session = $this->sessions->signIn(
            $body->requiredString('email'),
            $body->requiredSecret('password'),
        );

        return RefreshCookie::set(
            new JsonResponse(SessionPresenter::one($session), 200),
            $request,
            $session->refreshToken,
            $session->refreshLifetimeSeconds,
        );
    }
}
