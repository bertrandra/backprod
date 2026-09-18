<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/invoices/{invoiceId}.
 *
 * The lookup is scoped by tenant and product, so an invoice belonging to
 * another company answers 404 rather than 403: confirming it exists would
 * tell the caller that company is a customer.
 */
final class ShowInvoiceController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);

        return new JsonResponse(
            InvoicePresenter::one($this->invoicing->show(
                $context->tenantId,
                $context->productId,
                BillingRoute::invoiceId($request),
                $context->documentsOf(),
            )),
            200,
        );
    }
}
