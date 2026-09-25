<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/orders — buy an offer without a quote first.
 *
 * **The buyer's act**, behind `billing.pay` since 2026-09-25 — placing an
 * order is buying, and an administrator administers (ADR-055). It answered
 * to `sales.manage` before, which is the administrator's, so the one person
 * the model says does not buy was the only one who could reach this and the
 * member who does could not.
 *
 * The checkout hid that: it calls `Sales::order` through the service, so no
 * route permission applied, and the self-serve purchase worked for a member
 * all along. This route was simply pointing at the wrong person.
 *
 * What it leaves is a two-party flow that matches the model: the member
 * places the order, and fulfilling it — raising the organisation's invoice —
 * stays with `sales.manage`, because that is the seller's act.
 */
final class PlaceOrderController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::payable($request);

        return new JsonResponse(
            SalesPresenter::order($this->sales->order(
                $context->tenantId,
                $context->productId,
                JsonBody::of($request)->requiredString('offer_id', 64),
                $context->userId,
            )),
            201,
        );
    }
}
