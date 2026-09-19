<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\SubscriptionPeople;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/subscription/people/{userId} — the owner removes somebody
 * (2026-09-19). `?seat=1` names the caller's own seat. The person keeps
 * their account and their membership; they lose what the subscription
 * entitled them to.
 */
final class RemovePersonController implements RouteHandler
{
    public function __construct(private readonly SubscriptionPeople $people)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $seat = ($request->getQueryParams()['seat'] ?? null) !== null;
        $userId = $request->getAttribute('userId');

        $this->people->remove(
            $context->tenantId,
            $context->productId,
            $context->userId,
            $seat,
            is_string($userId) ? $userId : '',
        );

        return new EmptyResponse(204);
    }
}
