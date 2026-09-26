<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Freemium;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/subscription/freemium — take the free period (spec §6).
 *
 * The body names an offer and nothing else. No subscriber: what is taken is
 * always the caller's own seat, and whose it is comes from the resolved context
 * (ADR-055) — a request that cannot express the other sale is the difference
 * between selling seats and merely not offering anything else. No duration
 * either: how long a product gives itself away is the operator's configuration,
 * and a client that could name a number would name a larger one.
 *
 * `billing.pay`, which since 2026-09-25 is the **member's** permission and not
 * the administrator's. Acquiring a subscription for oneself is what it names,
 * and it stays the right gate when the price happens to be zero: an
 * administrator administers, and nobody takes somebody else's free period for
 * them.
 *
 * It answers 201 with the subscription, because one was created. There is no
 * checkout session to poll and no invoice to pay — that is the whole of §6.3.
 */
final class StartFreemiumController implements RouteHandler
{
    public function __construct(private readonly Freemium $freemium)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('billing.pay');

        $subscription = $this->freemium->take(
            $context->tenantId,
            $context->productId,
            JsonBody::of($request)->requiredString('offer_id', 64),
            $context->userId,
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 201);
    }
}
