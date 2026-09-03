<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/sales/quotes/{quoteId}.
 */
final class ShowQuoteController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::readable($request);

        return new JsonResponse(
            SalesPresenter::quote($this->sales->showQuote(
                $context->tenantId,
                $context->productId,
                SalesRoute::id($request, 'quoteId'),
            )),
            200,
        );
    }
}
