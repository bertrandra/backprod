<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Notification\Domain\Category;
use App\Notification\Domain\Channel;
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
 * `mutable` says which switches are real: security cannot be turned off.
 */
final class ShowPreferencesController implements RouteHandler
{
    public function __construct(private readonly Notifications $notifications)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = NotificationRoute::readable($request);

        $gate = $this->notifications->gateFor($context->userId, $context->productId);
        $preferences = [];

        foreach (Category::all() as $category) {
            foreach (Channel::all() as $channel) {
                $preferences[] = [
                    'category' => $category,
                    'channel' => $channel,
                    'enabled' => $gate->allows($category, $channel),
                    'mutable' => Category::isMutable($category),
                    'requires_consent' => Channel::requiresConsent($channel),
                ];
            }
        }

        return new JsonResponse(['preferences' => $preferences], 200);
    }
}
