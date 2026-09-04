<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\Taxation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tax/transactions — the fiscal history, filterable.
 *
 * Scoped by tenant *and* product like everything else. A fiscal fact is as
 * sensitive as the invoice that produced it.
 */
final class ListVatTransactionsController implements RouteHandler
{
    public function __construct(private readonly Taxation $taxation)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::readable($request);
        [$limit, $offset] = TaxRoute::page($request);

        $page = $this->taxation->transactions(
            $context->tenantId,
            $context->productId,
            TaxRoute::query($request, 'country'),
            TaxRoute::date($request, 'from'),
            TaxRoute::date($request, 'until'),
            $limit,
            $offset,
        );

        return new JsonResponse([
            'transactions' => array_map(TaxPresenter::transaction(...), $page['transactions']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
