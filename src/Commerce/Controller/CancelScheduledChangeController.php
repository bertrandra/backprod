<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Subscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/subscription/pending — undo a scheduled change of plan.
 *
 * The counterpart of the schedule, and not an optional one: a future change
 * that cannot be withdrawn is a cancellation in disguise (spec §4.2).
 * Nothing had happened yet, so undoing it restores nothing — it removes an
 * intention and records that it was removed.
 */
final class CancelScheduledChangeController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $subscription = $this->subscriptions->cancelScheduledChange(
            $context->tenantId,
            $context->productId,
            $context->userId,
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 200);
    }
}
