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
 * GET /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement —
 * what the platform gave, or null. Read with `staff.tenants.read`, like the
 * tenant itself: a grant is the platform's own decision, not the
 * customer's data, so no motive is asked for.
 */
final class ShowTenantEntitlementController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        $granted = $this->desk->grantOf(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
        );

        return new JsonResponse(['entitlement' => $granted === null ? null : StaffPresenter::grant($granted)], 200);
    }
}
