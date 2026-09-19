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
 * `POST /api/v1/auth/password/forgot` — the link to set a new one (2026-09-19).
 *
 * Public, under §31's public rate limit. **Always 202**, whether or not the
 * address has an account: the answer that would tell the two apart is the
 * enumeration the sign-in form is built to refuse.
 */
final class ForgotPasswordController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $email = JsonBody::of($request)->requiredString('email', 254);

        $this->sessions->forgotPassword($email);

        return new JsonResponse(['accepted' => true], 202);
    }
}
