<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Payment\Controller\PaymentPresenter;
use App\Payment\Domain\CollectedInvoices;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\TenantReads;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants/{tenantId}/payments[?product=code] — read-only,
 * with a motive, on the record (R14). See {@see TenantReads}.
 *
 * The same shape the customer's own screen reads, presented by the same
 * presenter: the console sees what the customer sees, no more and no less.
 */
final class ListTenantPaymentsController implements RouteHandler
{
    public function __construct(
        private readonly TenantReads $reads,
        private readonly CollectedInvoices $collected,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        $product = $request->getQueryParams()['product'] ?? null;

        $rows = $this->reads->payments(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            is_string($product) && trim($product) !== '' ? strtolower(trim($product)) : null,
            StaffRoute::motive($request),
        );

        // The same extra read the customer's own screen makes (2026-09-26):
        // the console sees what the customer sees, no more and no less, and
        // "whose payment is this?" is the question a support call opens with.
        $collected = $this->collected->of(PaymentPresenter::invoicesOf($rows));

        return new JsonResponse(['payments' => PaymentPresenter::many($rows, $collected)], 200);
    }
}
