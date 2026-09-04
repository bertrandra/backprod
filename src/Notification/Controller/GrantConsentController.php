<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Domain\Consent;
use App\Notification\Service\Notifications;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/notifications/consents — opting in to SMS or WhatsApp.
 *
 * The request records *how* the consent was given, and the request id and the
 * moment go in as evidence. Consent that cannot be evidenced is consent that
 * cannot be relied on, and the question asked later is always "when, and on
 * what basis?".
 */
final class GrantConsentController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::manageable($request);
        $body = JsonBody::of($request);

        $requestId = $request->getAttribute('request_id');

        $consent = $this->notifications->grantConsent(
            $context->userId,
            strtoupper($body->requiredString('channel', 16)),
            strtoupper($body->has('purpose') ? $body->requiredString('purpose', 16) : Consent::TRANSACTIONAL),
            $body->has('source') ? $body->requiredString('source', 64) : 'api',
            [
                'request_id' => is_string($requestId) ? $requestId : null,
                'granted_via' => 'api',
                'at' => gmdate('c'),
            ],
        );

        return new JsonResponse(['consent' => NotificationPresenter::consent($consent)], 201);
    }
}
