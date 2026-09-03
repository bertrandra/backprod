<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/orders/{orderId}/cancel.
 *
 * Only before fulfilment. A completed order raised an invoice and started a
 * subscription, and undoing that is a credit note and a cancellation — both
 * deliberate acts with their own documents, not a side effect of this one.
 */
final class CancelOrderController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);

        return new JsonResponse(
            SalesPresenter::order($this->sales->cancelOrder(
                $context->tenantId,
                $context->productId,
                SalesRoute::id($request, 'orderId'),
                $context->userId,
            )),
            200,
        );
    }
}
