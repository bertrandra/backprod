<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\CreditNotes;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices/{invoiceId}/credit.
 *
 * Credits the invoice in full and moves it to CREDITED. A reason may be
 * given and is kept on the document, because "why was this credited" is the
 * first question anybody asks about one a year later.
 */
final class IssueCreditNoteController implements RouteHandler
{
    public function __construct(private readonly CreditNotes $creditNotes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);
        $body = JsonBody::of($request);

        $note = $this->creditNotes->issue(
            $context->tenantId,
            $context->productId,
            BillingRoute::invoiceId($request),
            $body->has('reason') ? $body->requiredString('reason', 500) : null,
            $context->userId,
        );

        return new JsonResponse(CreditNotePresenter::one($note), 201);
    }
}
