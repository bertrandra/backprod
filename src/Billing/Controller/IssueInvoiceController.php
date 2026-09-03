<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices — bill the current subscription period.
 *
 * There is no body. What to invoice is not the caller's to choose: it is the
 * subscription the tenant actually holds and the period it is actually in.
 * Accepting an amount here would let a client name its own price.
 *
 * In production the caller is M7's scheduler rather than a person; the
 * endpoint exists now so the path is exercised before a job depends on it.
 */
final class IssueInvoiceController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::manageable($request);

        return new JsonResponse(
            InvoicePresenter::one($this->invoicing->issueForSubscription(
                $context->tenantId,
                $context->productId,
                $context->userId,
            )),
            201,
        );
    }
}
