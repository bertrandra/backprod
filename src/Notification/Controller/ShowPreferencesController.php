<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Service\Notifications;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/notifications/preferences.
 *
 * Returns the full matrix with defaults filled in, not just the rows somebody
 * has touched. A client rendering a settings screen should not have to know
 * that an absent row means enabled — and that marketing is the one exception.
 * The map the contract declares, keyed `CATEGORY.CHANNEL` (2026-10-26; it was
 * a list of rows the screen could not read).
 */
final class ShowPreferencesController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);

        return new JsonResponse([
            'preferences' => (object) $this->notifications->matrix($context->userId, $context->productId),
        ], 200);
    }
}
