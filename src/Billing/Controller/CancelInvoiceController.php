<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices/{invoiceId}/cancel.
 *
 * Cancelling marks the document void; it never deletes it. The number stays
 * allocated and the row stays readable, because the sequence must have no
 * gaps and an auditor asking about 2026-000042 must get an answer even when
 * the answer is "cancelled".
 *
 * A paid invoice cannot be cancelled — money has moved, and the instrument
 * for that is a credit note. The state machine refuses it rather than this
 * controller, so every path into the transition is refused the same way.
 */
final class CancelInvoiceController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);

        return new JsonResponse(
            InvoicePresenter::one($this->invoicing->cancel(
                $context->tenantId,
                $context->productId,
                BillingRoute::invoiceId($request),
                $context->userId,
            )),
            200,
        );
    }
}
