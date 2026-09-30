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
 * DELETE /api/v1/organisation/subscriptions/{subscriptionId}/me — an
 * administrator takes themselves back off (2026-09-30).
 *
 * Idempotent, like every other removal on this platform: taking yourself off
 * something you are not on is nothing, not an error. The entitlement goes with
 * the row, which is what being on a subscription was for.
 *
 * `tenant.manage`, and no body, for the reasons {@see JoinSubscriptionController}
 * gives. The owner's list is untouched — an administrator can remove
 * themselves and nobody else.
 */
final class LeaveSubscriptionController implements RouteHandler
{
    public function __construct(private readonly SubscriptionPeople $people)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $this->people->leaveAsAdministrator(
            $context->tenantId,
            $context->productId,
            $context->userId,
            SubscriptionRoute::subscriptionId($request),
        );

        return new EmptyResponse(204);
    }
}
