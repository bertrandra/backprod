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
 * GET /api/v1/subscription.
 *
 * Returns the live subscription, its history and its event log together: a
 * tenant asking what they are on usually also wants to know what they were
 * on, and splitting that across three round trips serves nobody.
 *
 * A tenant with no subscription gets 200 and a null, not a 404 — having none
 * is a normal state, not a missing resource.
 */
final class ShowSubscriptionController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $current = $this->subscriptions->current($context->tenantId, $context->productId);

        $seat = $this->subscriptions->seatOf($context->tenantId, $context->productId, $context->userId);

        return new JsonResponse([
            'subscription' => $current === null ? null : SubscriptionPresenter::one($current),
            // The caller's own seat, beside the organisation's (2026-09-18):
            // what the catalogue offers to buy depends on both.
            'seat' => $seat === null ? null : SubscriptionPresenter::one($seat),
            'history' => SubscriptionPresenter::many(
                $this->subscriptions->history($context->tenantId, $context->productId),
            ),
            'events' => SubscriptionPresenter::events(
                $this->subscriptions->events($context->tenantId, $context->productId),
            ),
        ], 200);
    }
}
