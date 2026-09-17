<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement —
 * take back what the platform gave. The rows go; whatever a subscription
 * grants on the same product is untouched, because it was never this.
 */
final class WithdrawTenantEntitlementController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);

        $this->desk->withdrawEntitlement(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::id($request, 'productId'),
        );

        return new EmptyResponse(204);
    }
}
