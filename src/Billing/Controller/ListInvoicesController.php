<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/invoices.
 *
 * Paged and bounded whether or not the caller asks. A tenant billed monthly
 * for years has hundreds of invoices, and an accountant's export must not be
 * able to ask for all of them in one response.
 */
final class ListInvoicesController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->invoicing->list(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
            $context->documentsOf(),
        );

        return new JsonResponse([
            'invoices' => InvoicePresenter::many($page['invoices']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
