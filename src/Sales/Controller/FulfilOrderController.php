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
 * Raises the order's invoice and leaves it AWAITING_PAYMENT: the subscription
 * starts when that invoice is paid, not when this returns. The response
 * carries the invoice to pay, and `subscription_id` stays null until it is —
 * which is the gate, visible in the document rather than only in the code.
 *
 * An order with nothing to collect comes back COMPLETED, because there is no
 * payment for it to wait on.
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
