<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/notifications/preferences.
 *
 * One (category, channel) pair at a time. Switching off a security category
 * is refused here with a message, and refused again by a CHECK constraint if
 * anything ever reaches the table another way (non-negotiable #24).
 */
final class SavePreferenceController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::manageable($request);
        $body = JsonBody::of($request);

        $this->notifications->setPreference(
            $context->userId,
            $context->productId,
            strtoupper($body->requiredString('category', 16)),
            strtoupper($body->requiredString('channel', 16)),
            $body->optionalBool('enabled', true),
        );

        return new JsonResponse([
            'preferences' => $this->notifications->preferences($context->userId, $context->productId),
        ], 200);
    }
}
