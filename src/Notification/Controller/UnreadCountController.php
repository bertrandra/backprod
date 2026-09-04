<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/notifications/unread-count.
 *
 * Its own route because a badge is polled far more often than a list is read,
 * and it should not carry a page of payloads to answer with a number (R2:
 * no persistent process, so the client polls).
 */
final class UnreadCountController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);

        return new JsonResponse([
            'unread' => $this->notifications->unreadCount($context->userId, $context->productId),
        ], 200);
    }
}
