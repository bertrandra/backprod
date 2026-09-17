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
 * Scoped by tenant, and since 2026-09-17 across every product the tenant
 * holds (docs/tenant-roots.md §2.7): a customer's VAT picture is one, and
 * the product a screen was opened in is a filter (`?product=`) rather than
 * a wall. `X-Product` still resolves the context — who is asking, and that
 * they may — it just no longer decides what is answered.
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
            TaxRoute::query($request, 'product'),
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
