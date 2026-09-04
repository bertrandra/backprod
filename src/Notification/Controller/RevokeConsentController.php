<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/notifications/consents/{consentId}.
 *
 * Dated, never deleted. Revoking twice keeps the first date, because when
 * permission ended is a fact and not a preference.
 */
final class RevokeConsentController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::manageable($request);

        $consent = $this->notifications->revokeConsent(
            $context->userId,
            NotificationRoute::consentId($request),
        );

        return new JsonResponse(['consent' => NotificationPresenter::consent($consent)], 200);
    }
}
