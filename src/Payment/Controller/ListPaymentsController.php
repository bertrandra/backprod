<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Domain\CollectedInvoices;
use App\Payment\Service\Payments;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/payments.
 */
final class ListPaymentsController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly Payments $payments,
        private readonly CollectedInvoices $collected,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = PaymentRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->payments->list(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
            $context->documentsOf(),
        );

        // One extra read for the whole page, never one per row (2026-09-26):
        // the documents these attempts collect, so each row can say whose it
        // is. An administrator sees every payment the organisation has, and
        // twelve identical amounts with no name on them are twelve identical
        // rows.
        $collected = $this->collected->of(PaymentPresenter::invoicesOf($page['payments']));

        return new JsonResponse([
            'payments' => PaymentPresenter::many($page['payments'], $collected),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
