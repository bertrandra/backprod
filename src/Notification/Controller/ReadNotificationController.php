<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/notifications/{notificationId}/read.
 *
 * Reading twice does not move the timestamp: "when did they see it?" has one
 * answer, and the first one is the true one.
 */
final class ReadNotificationController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::manageable($request);

        $notification = $this->notifications->markRead(
            $context->userId,
            $context->productId,
            NotificationRoute::notificationId($request),
        );

        return new JsonResponse([
            'notification' => NotificationPresenter::notification($notification),
        ], 200);
    }
}
