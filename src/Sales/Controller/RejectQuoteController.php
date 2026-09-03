<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/quotes/{quoteId}/reject.
 */
final class RejectQuoteController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);

        return new JsonResponse(
            SalesPresenter::quote($this->sales->rejectQuote(
                $context->tenantId,
                $context->productId,
                SalesRoute::id($request, 'quoteId'),
                $context->userId,
            )),
            200,
        );
    }
}
