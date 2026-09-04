<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/notifications/{notificationId}/deliveries.
 *
 * What was attempted, on which channel, and what came of it — including the
 * suppressions. This is the endpoint that answers "did you email me about
 * this?" with something better than a shrug.
 */
final class ShowDeliveriesController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);

        $deliveries = $this->notifications->deliveriesFor(
            $context->userId,
            $context->productId,
            NotificationRoute::notificationId($request),
        );

        return new JsonResponse([
            'deliveries' => array_map(NotificationPresenter::delivery(...), $deliveries),
        ], 200);
    }
}
