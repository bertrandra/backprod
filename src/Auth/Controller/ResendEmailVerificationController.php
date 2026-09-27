<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\Sessions;
use App\Shared\Context\IdentityContext;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/auth/verify-email/resend` — a new confirmation link (ADR-061).
 *
 * **Identity-only**, like product discovery: it needs to know who is asking and
 * nothing else, and it must stay reachable from the one state in which the
 * tenant surface refuses them — an address still unproved past its deadline.
 * Requiring a product would put the way out behind the door it opens.
 *
 * Answers whether a link went, so a screen can say "sent" or "already
 * confirmed" truthfully; it reveals nothing the person does not already know
 * about their own account.
 */
final class ResendEmailVerificationController implements RouteHandler
{
    public function __construct(private readonly Sessions $sessions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);

        return new JsonResponse(['sent' => $this->sessions->resendConfirmation($identity->userId)], 200);
    }
}
