<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/staff/tenants/{tenantId}/products/{productId} — take a
 * product back from a tenant (ADR-047).
 *
 * The memberships in that product go with it, by the schema's cascade; the
 * records keyed on it — projects, invoices, conversations — stay, because a
 * record of what happened is not access to it and an invoice is a document
 * the law keeps. Refused with 409 `PRODUCT_IN_USE` while a subscription on
 * the product is still owed service: the customer paid for it.
 *
 * 200 with the tenant rather than 204, because the console wants the tenant
 * as it now stands, and 200 for a product the tenant never held — that is
 * the state that was asked for.
 */
final class UnassignTenantProductController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);

        $tenant = $this->desk->unassignProduct(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
        );

        return new JsonResponse(['tenant' => StaffPresenter::tenant($tenant)], 200);
    }
}
