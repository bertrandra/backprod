<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/sales/quotes.
 */
final class ListQuotesController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->sales->quotes(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
        );

        return new JsonResponse([
            'quotes' => SalesPresenter::quotes($page['quotes']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
