<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/notifications/consents.
 *
 * Revoked consents are listed too, dated. They are the record that permission
 * once existed, which is what proof means — deleting them would leave nothing
 * to show either way.
 */
final class ListConsentsController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);

        return new JsonResponse([
            'consents' => array_map(
                NotificationPresenter::consent(...),
                $this->notifications->consents($context->userId),
            ),
        ], 200);
    }
}
