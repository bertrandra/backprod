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
 * GET /api/v1/billing/invoices/{invoiceId}/transmissions.
 *
 * Every attempt, oldest first — not just the latest. §25.1 keeps the
 * platform's identifiers and statuses so somebody can prove what happened,
 * and "it was rejected, corrected and accepted" is the answer, not
 * "accepted".
 */
final class ListTransmissionsController implements RouteHandler
{
    public function __construct(private readonly EInvoicing $einvoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);

        return new JsonResponse([
            'transmissions' => TransmissionPresenter::many($this->einvoicing->forInvoice(
                $context->tenantId,
                $context->productId,
                BillingRoute::invoiceId($request),
            )),
        ], 200);
    }
}
