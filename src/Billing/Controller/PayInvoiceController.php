<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices/{invoiceId}/pay.
 *
 * Records that an invoice was settled — a bank transfer reconciled by hand,
 * which is how most B2B invoices in France are actually paid.
 *
 * It takes no payment details, and it never will: §24 puts card data outside
 * this platform entirely, and the card path arrives as a provider webhook
 * that calls the same transition rather than as a field on this request.
 */
final class PayInvoiceController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);

        return new JsonResponse(
            InvoicePresenter::one($this->invoicing->markPaid(
                $context->tenantId,
                $context->productId,
                BillingRoute::invoiceId($request),
                $context->userId,
            )),
            200,
        );
    }
}
