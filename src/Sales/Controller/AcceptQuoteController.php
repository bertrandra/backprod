<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/quotes/{quoteId}/accept — becomes an order.
 *
 * The order's lines are the quote's, copied: the customer agreed to those
 * amounts, and re-deriving them from an offer that may have been repriced
 * since would bill them for something else.
 */
final class AcceptQuoteController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);

        return new JsonResponse(
            SalesPresenter::order($this->sales->acceptQuote(
                $context->tenantId,
                $context->productId,
                SalesRoute::id($request, 'quoteId'),
                $context->userId,
            )),
            201,
        );
    }
}
