<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/orders/{orderId}/fulfil.
 *
 * Starts the subscription and raises the invoice, in one transaction with the
 * order completing. The response carries both ids, which is §20's chain
 * readable in a single document.
 */
final class FulfilOrderController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);

        return new JsonResponse(
            SalesPresenter::order($this->sales->fulfil(
                $context->tenantId,
                $context->productId,
                SalesRoute::id($request, 'orderId'),
                $context->userId,
            )),
            200,
        );
    }
}
