<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Billing\Domain\DocumentPeople;
use App\Payment\Controller\PaymentPresenter;
use App\Payment\Domain\CollectedInvoices;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\TenantReads;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants/{tenantId}/payments[?product=code] — read-only.
 * See {@see TenantReads}.
 *
 * The same shape the customer's own screen reads, presented by the same
 * presenter: the console sees what the customer sees, no more and no less.
 */
final class ListTenantPaymentsController implements RouteHandler
{
    public function __construct(
        private readonly TenantReads $reads,
        private readonly CollectedInvoices $collected,
        private readonly DocumentPeople $people,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        $product = $request->getQueryParams()['product'] ?? null;
        $tenantId = StaffRoute::id($request, 'tenantId');

        $rows = $this->reads->payments(
            $context->identity,
            $tenantId,
            is_string($product) && trim($product) !== '' ? strtolower(trim($product)) : null,
        );

        // The same extra read the customer's own screen makes (2026-09-26):
        // the console sees what the customer sees, no more and no less, and
        // "whose payment is this?" is the question a support call opens with.
        //
        // The tenant here is the **path's**, which is the one the permission
        // was checked against. A staff route is authorized by the platform
        // role and never by the parameter, so reading these documents under
        // any other tenant would be reading outside what was authorized.
        $collected = $this->collected->of($tenantId, PaymentPresenter::invoicesOf($rows));

        // And the member each one is for (2026-09-27), under the same tenant.
        $people = $this->people->ofPayments($tenantId, array_map(static fn ($payment): string => $payment->id, $rows));

        return new JsonResponse(['payments' => PaymentPresenter::many($rows, $collected, $people)], 200);
    }
}
