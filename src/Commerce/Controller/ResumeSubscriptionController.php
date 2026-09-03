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
 * POST /api/v1/subscription/resume — withdraw a scheduled cancellation.
 *
 * Only meaningful while the subscription is still running. One that has
 * already ended is restarted by subscribing again, not by resuming: the
 * terms it ended on may not even be for sale any more.
 */
final class ResumeSubscriptionController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $subscription = $this->subscriptions->resume(
            $context->tenantId,
            $context->productId,
            $context->userId,
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 200);
    }
}
