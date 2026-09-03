<?php

declare(strict_types=1);

namespace App\EInvoice\Controller;

use App\Billing\Controller\BillingRoute;
use App\EInvoice\Service\EInvoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices/{invoiceId}/transmit.
 *
 * Hands the invoice to the configured approved platform. 202, not 201: the
 * document is lodged, and whether it is accepted is the platform's answer to
 * give, arriving later as a webhook.
 */
final class SubmitInvoiceController implements RouteHandler
{
    public function __construct(private readonly EInvoicing $einvoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);

        return new JsonResponse(
            TransmissionPresenter::one($this->einvoicing->submit(
                $context->tenantId,
                $context->productId,
                BillingRoute::invoiceId($request),
                $context->userId,
            )),
            202,
        );
    }
}
