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
 * The self-serve path. Buying from a quote goes through
 * `/sales/quotes/{id}/accept` instead, because accepting is a decision about
 * a document rather than a new purchase.
 */
final class PlaceOrderController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);

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
