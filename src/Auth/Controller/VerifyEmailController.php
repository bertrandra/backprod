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
 * `POST /api/v1/auth/verify-email` — the link from the confirmation mail.
 *
 * Public, because the person following it may be on a device that has never
 * signed in — which is half the point of confirming an address.
 *
 * **It never signs anybody in.** A link that produced a session would be a
 * credential sitting in an inbox for as long as that mail is kept, readable by
 * anybody who ever gains access to it. This confirms an address and nothing
 * else; the person signs in afterwards, as themselves.
 *
 * Unknown, expired and already-used are one answer. A public endpoint that
 * told them apart would let somebody probe tokens.
 */
final class VerifyEmailController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = JsonBody::of($request)->requiredString('token', 128);

        if (!$this->sessions->confirmEmail($token)) {
            throw new BadRequestException(
                'VERIFICATION_FAILED',
                'That confirmation link is no longer valid. Ask for a new one from your profile.',
            );
        }

        return new JsonResponse(['verified' => true], 200);
    }
}
