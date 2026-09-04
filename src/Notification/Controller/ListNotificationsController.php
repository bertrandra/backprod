<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/notifications — the screen channel.
 *
 * Scoped by the *resolved* user, never by an id in the request. A
 * notification is addressed to one person, and asking for somebody else's is
 * not a thing the API can express.
 */
final class ListNotificationsController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);
        [$limit, $offset] = NotificationRoute::page($request);

        $page = $this->notifications->listFor(
            $context->userId,
            $context->productId,
            NotificationRoute::unreadOnly($request),
            $limit,
            $offset,
        );

        return new JsonResponse([
            'notifications' => array_map(
                NotificationPresenter::notification(...),
                $page['notifications'],
            ),
            'total' => $page['total'],
            'unread' => $page['unread'],
            'limit' => $limit,
            'offset' => $offset,
        ], 200);
    }
}
