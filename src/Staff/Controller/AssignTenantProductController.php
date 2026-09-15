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
 * PUT /api/v1/staff/tenants/{tenantId}/products/{productId} — give a tenant
 * a product (ADR-047).
 *
 * PUT with no body: the address states the desired state — this tenant holds
 * this product — and a second identical request is the same state, which is
 * what a console toggle clicked twice on a slow connection needs.
 *
 * No motive header, like the delegation beside it: nothing of the customer's
 * is revealed, what changes is what they may reach, and the trail records
 * who decided it. `staff.tenants.manage`, so PLATFORM_ADMIN alone — a
 * support engineer able to hand a customer a product is one able to hand
 * them a bill for it.
 */
final class AssignTenantProductController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);

        $tenant = $this->desk->assignProduct(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
        );

        return new JsonResponse(['tenant' => StaffPresenter::tenant($tenant)], 200);
    }
}
