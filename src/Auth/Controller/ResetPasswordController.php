<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/password/reset` — the link from the mail, and the new
 * password (2026-09-19).
 *
 * Public, because the person may be on a device that never signed in. **It
 * signs nobody in** — the opposite: every session of the account is
 * revoked, and the person signs in afresh with what they just chose. Unknown,
 * expired and spent links are one answer, so nobody can probe tokens.
 */
final class ResetPasswordController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::of($request);
        $token = $body->requiredString('token', 128);
        $password = $body->requiredSecret('password');

        if (!$this->sessions->resetPassword($token, $password)) {
            throw new BadRequestException(
                'RESET_LINK_INVALID',
                'That link is no longer valid — it may have been used already or expired. Ask for a new one.',
            );
        }

        return new JsonResponse(['reset' => true], 200);
    }
}
